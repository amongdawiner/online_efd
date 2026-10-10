<?php

namespace OnlineEfd\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use OnlineEfd\Data\ReceiptData;
use OnlineEfd\Events\ReceiptFailed;
use OnlineEfd\Events\ReceiptPosted;
use OnlineEfd\Exceptions\EfdException;
use OnlineEfd\Models\EfdProfile;
use OnlineEfd\Models\EfdReceipt;
use OnlineEfd\Support\Xml;
use OnlineEfd\Tra\TraClient;

class ReceiptService
{
    public function __construct(
        private readonly TraClient $client,
        private readonly TokenService $tokens,
        private readonly ReceiptCalculator $calculator,
    ) {
    }

    /**
     * Reserve counters and persist the receipt (status "pending") without
     * contacting TRA. Idempotent per $receivable: if that sale already has a
     * receipt (even a failed one, which issue()/post() will simply retry) it is
     * returned instead of burning a second counter.
     */
    public function create(EfdProfile $profile, ReceiptData $data, ?Model $receivable = null, bool $test = false): EfdReceipt
    {
        $profile->assertReady();

        if ($receivable && ! $test) {
            $existing = EfdReceipt::query()
                ->where('efd_profile_id', $profile->getKey())
                ->where('receivable_type', $receivable->getMorphClass())
                ->where('receivable_id', $receivable->getKey())
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        $calc = $this->calculator->calculate($data->items, $profile->taxRates());
        $issuedAt = ($data->issuedAt ?? now())->copy()->setTimezone(config('efd.timezone'));
        $customer = $data->customer;

        return DB::transaction(function () use ($profile, $data, $receivable, $calc, $issuedAt, $customer, $test) {
            [$gc, $dc] = $profile->allocateCounters();

            return EfdReceipt::create([
                'efd_profile_id' => $profile->getKey(),
                'receivable_type' => $receivable?->getMorphClass(),
                'receivable_id' => $receivable?->getKey(),
                'rct_date' => $issuedAt->format('Y-m-d'),
                'rct_time' => $issuedAt->format('H:i:s'),
                'rctnum' => $gc,
                'gc' => $gc,
                'dc' => $dc,
                'znum' => $issuedAt->format('Ymd'),
                'rctvnum' => $profile->receipt_code,
                'customer_id_type' => $customer?->idType->value ?? 6,
                'customer_id' => $customer?->id,
                'customer_name' => $customer?->name,
                'customer_phone' => $customer?->phone,
                'items' => $calc['lines'],
                'total_excl' => $calc['total_excl'],
                'total_incl' => $calc['total_incl'],
                'vat_totals' => $calc['vat'],
                'payment_type' => $data->paymentType->value,
                'status' => EfdReceipt::PENDING,
                'is_test' => $test,
            ]);
        });
    }

    /**
     * Send a receipt flagged as a test. TRA still receives it and it uses the next
     * counters, but it is excluded from Z-reports and never blocks one.
     */
    public function issueTest(EfdProfile $profile, ReceiptData $data): EfdReceipt
    {
        return $this->post($this->create($profile, $data, null, true));
    }

    /** Create and immediately post. Throws EfdException on failure (the receipt stays stored as "failed" for retry). */
    public function issue(EfdProfile $profile, ReceiptData $data, ?Model $receivable = null): EfdReceipt
    {
        return $this->post($this->create($profile, $data, $receivable));
    }

    /** Sign and send a stored receipt. Safe to call again on failed/pending receipts. */
    public function post(EfdReceipt $receipt): EfdReceipt
    {
        if ($receipt->isPosted()) {
            return $receipt;
        }

        $profile = $receipt->profile;

        try {
            $profile->assertReady();

            $certificate = $profile->certificate();
            $signed = Xml::envelope($this->payload($receipt, $profile), $certificate);
            $receipt->forceFill(['request_xml' => $signed, 'attempts' => $receipt->attempts + 1])->save();

            $token = $this->tokens->get($profile);
            $response = $this->client->submit(
                'receipt',
                $profile->routing_key ?: config('efd.api.routing_keys.receipt'),
                $signed,
                $certificate,
                $token,
            );

            $ack = Xml::ack($response);
            $receipt->forceFill([
                'response_xml' => $response->asXML(),
                'ack_code' => $ack['code'],
                'ack_message' => $ack['message'],
            ]);

            if ($ack['code'] !== '0') {
                throw EfdException::rejected($ack['code'], $ack['message']);
            }
        } catch (EfdException $e) {
            $receipt->forceFill(['status' => EfdReceipt::FAILED, 'ack_message' => $receipt->ack_message ?: $e->getMessage()])->save();
            ReceiptFailed::dispatch($receipt, $e->getMessage());

            throw $e->withReceipt($receipt);
        }

        $receipt->forceFill([
            'status' => EfdReceipt::POSTED,
            'posted_at' => now(),
            'verification_url' => $this->verificationUrl($receipt),
        ])->save();

        ReceiptPosted::dispatch($receipt);

        return $receipt;
    }

    /**
     * Public TRA verification link: {RCTVNUM}{GC}_{HHMMSS}.
     * NOTE: format taken from TRA's receipt-verification convention; confirm against a real receipt.
     */
    public function verificationUrl(EfdReceipt $receipt): string
    {
        return rtrim(config('efd.verification_url'), '/').'/'
            .$receipt->rctvnum.$receipt->gc.'_'.str_replace(':', '', $receipt->rct_time);
    }

    private function payload(EfdReceipt $r, EfdProfile $profile): string
    {
        $xml = '<RCT>'
            .Xml::tag('DATE', $r->rct_date)
            .Xml::tag('TIME', $r->rct_time)
            .Xml::tag('TIN', $profile->tin)
            .Xml::tag('REGID', $profile->regid)
            .Xml::tag('EFDSERIAL', $profile->efd_serial ?: $profile->cert_key)
            .Xml::tag('CUSTIDTYPE', $r->customer_id_type)
            .Xml::tag('CUSTID', $r->customer_id)
            .Xml::tag('CUSTNAME', $r->customer_name)
            .Xml::tag('MOBILENUM', $r->customer_phone)
            .Xml::tag('RCTNUM', $r->rctnum)
            .Xml::tag('DC', $r->dc)
            .Xml::tag('GC', $r->gc)
            .Xml::tag('ZNUM', $r->znum)
            .Xml::tag('RCTVNUM', $r->rctvnum);

        $xml .= '<ITEMS>';
        foreach ($r->items as $item) {
            $xml .= '<ITEM>'
                .Xml::tag('ID', $item['id'])
                .Xml::tag('DESC', $item['desc'])
                .Xml::tag('QTY', $item['qty'])
                .Xml::tag('TAXCODE', $item['taxcode'])
                .Xml::tag('AMT', $item['amt'])
                .'</ITEM>';
        }
        $xml .= '</ITEMS>';

        $xml .= '<TOTALS>'
            .Xml::tag('TOTALTAXEXCL', $r->total_excl)
            .Xml::tag('TOTALTAXINCL', $r->total_incl)
            .Xml::tag('DISCOUNT', '0.00')
            .'</TOTALS>';

        $xml .= '<PAYMENTS>'
            .Xml::tag('PMTTYPE', $r->payment_type)
            .Xml::tag('PMTAMOUNT', $r->total_incl)
            .'</PAYMENTS>';

        $xml .= '<VATTOTALS>';
        foreach ($r->vat_totals as $letter => $bucket) {
            $xml .= Xml::tag('VATRATE', $letter)
                .Xml::tag('NETTAMOUNT', $bucket['net'])
                .Xml::tag('TAXAMOUNT', $bucket['tax']);
        }
        $xml .= '</VATTOTALS>';

        return $xml.'</RCT>';
    }
}
