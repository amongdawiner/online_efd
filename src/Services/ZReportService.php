<?php

namespace OnlineEfd\Services;

use Carbon\CarbonInterface;
use OnlineEfd\Exceptions\EfdException;
use OnlineEfd\Models\EfdProfile;
use OnlineEfd\Models\EfdReceipt;
use OnlineEfd\Models\EfdZReport;
use OnlineEfd\Support\Money;
use OnlineEfd\Support\Xml;
use OnlineEfd\Tra\TraClient;

class ZReportService
{
    private const PAYMENT_TYPES = ['CASH', 'CHEQUE', 'CCARD', 'EMONEY', 'INVOICE'];

    public function __construct(
        private readonly TraClient $client,
        private readonly TokenService $tokens,
    ) {
    }

    /**
     * Summarise the day's posted receipts and send the Z-report to TRA.
     * Returns the stored report; an already-posted day is returned untouched.
     */
    public function post(EfdProfile $profile, ?CarbonInterface $date = null): EfdZReport
    {
        $profile->assertReady();

        $date = ($date ?? now())->copy()->setTimezone(config('efd.timezone'));
        $znum = $date->format('Ymd');

        $report = EfdZReport::firstOrNew(['efd_profile_id' => $profile->getKey(), 'znum' => $znum]);
        if ($report->status === EfdZReport::POSTED) {
            return $report;
        }

        $unsent = EfdReceipt::query()
            ->where('efd_profile_id', $profile->getKey())
            ->where('znum', $znum)
            ->where('is_test', false)
            ->where('status', '!=', EfdReceipt::POSTED)
            ->count();

        if ($unsent > 0) {
            throw new EfdException("Cannot post the Z-report for {$date->toDateString()}: {$unsent} receipt(s) are not yet accepted by TRA.");
        }

        $summary = $this->summarise($profile, $znum);
        $gross = Money::cents($profile->gross) + $summary['daily_total_cents'];

        $certificate = $profile->certificate();
        $signed = Xml::envelope($this->payload($profile, $date, $summary, $gross), $certificate);

        $report->forceFill([
            'report_date' => $date->toDateString(),
            'daily_total' => Money::format($summary['daily_total_cents']),
            'gross' => Money::format($gross),
            'tickets_fiscal' => $summary['tickets'],
            'summary' => $summary,
            'request_xml' => $signed,
            'status' => EfdZReport::PENDING,
        ])->save();

        try {
            $response = $this->client->submit(
                'zreport',
                config('efd.api.routing_keys.zreport'),
                $signed,
                $certificate,
                $this->tokens->get($profile),
            );

            $ack = Xml::ack($response);
            $report->forceFill([
                'response_xml' => $response->asXML(),
                'ack_code' => $ack['code'],
                'ack_message' => $ack['message'],
            ]);

            if ($ack['code'] !== '0') {
                throw EfdException::rejected($ack['code'], $ack['message']);
            }
        } catch (EfdException $e) {
            $report->forceFill(['status' => EfdZReport::FAILED, 'ack_message' => $report->ack_message ?: $e->getMessage()])->save();

            throw $e;
        }

        $report->forceFill(['status' => EfdZReport::POSTED, 'posted_at' => now()])->save();
        $profile->forceFill(['gross' => Money::format($gross)])->save();

        return $report;
    }

    /** @return array{daily_total_cents: int, tickets: int, vat: array, payments: array<string,int>} */
    private function summarise(EfdProfile $profile, string $znum): array
    {
        $vat = array_fill_keys(['A', 'B', 'C', 'D', 'E'], ['net' => 0, 'tax' => 0]);
        $payments = array_fill_keys(self::PAYMENT_TYPES, 0);
        $total = 0;
        $tickets = 0;

        EfdReceipt::query()
            ->where('efd_profile_id', $profile->getKey())
            ->where('znum', $znum)
            ->where('is_test', false)
            ->where('status', EfdReceipt::POSTED)
            ->orderBy('id')
            ->each(function (EfdReceipt $r) use (&$vat, &$payments, &$total, &$tickets) {
                $incl = Money::cents($r->total_incl);
                $total += $incl;
                $tickets++;
                $payments[$r->payment_type] = ($payments[$r->payment_type] ?? 0) + $incl;

                foreach ($r->vat_totals as $letter => $bucket) {
                    $vat[$letter]['net'] += Money::cents($bucket['net']);
                    $vat[$letter]['tax'] += Money::cents($bucket['tax']);
                }
            });

        return ['daily_total_cents' => $total, 'tickets' => $tickets, 'vat' => $vat, 'payments' => $payments];
    }

    private function payload(EfdProfile $p, CarbonInterface $date, array $summary, int $gross): string
    {
        $xml = '<ZREPORT>'
            .Xml::tag('DATE', $date->format('Y-m-d'))
            .Xml::tag('TIME', now(config('efd.timezone'))->format('H:i:s'));

        $xml .= '<HEADER>';
        foreach ([$p->name, $p->tin, $p->vrn, $p->mobile, $p->street, $p->city, $p->country] as $line) {
            if (filled($line)) {
                $xml .= Xml::tag('LINE', $line);
            }
        }
        $xml .= '</HEADER>';

        $xml .= Xml::tag('VRN', $p->vrn)
            .Xml::tag('TIN', $p->tin)
            .Xml::tag('TAXOFFICE', $p->tax_office)
            .Xml::tag('REGID', $p->regid)
            .Xml::tag('ZNUMBER', $date->format('Ymd'))
            .Xml::tag('EFDSERIAL', $p->efd_serial ?: $p->cert_key)
            .Xml::tag('REGISTRATIONDATE', $p->registered_at->format('Y-m-d'))
            .Xml::tag('USER', $p->uin)
            .Xml::tag('SIMIMSI', 'WEBAPI');

        $xml .= '<TOTALS>'
            .Xml::tag('DAILYTOTALAMOUNT', Money::format($summary['daily_total_cents']))
            .Xml::tag('GROSS', Money::format($gross))
            .Xml::tag('CORRECTIONS', '0.00')
            .Xml::tag('DISCOUNTS', '0.00')
            .Xml::tag('SURCHARGES', '0.00')
            .Xml::tag('TICKETSVOID', '0')
            .Xml::tag('TICKETSVOIDTOTAL', '0.00')
            .Xml::tag('TICKETSFISCAL', $summary['tickets'])
            .Xml::tag('TICKETSNONFISCAL', '0')
            .'</TOTALS>';

        $rates = $p->taxRates();
        $xml .= '<VATTOTALS>';
        foreach ($summary['vat'] as $letter => $bucket) {
            $xml .= Xml::tag('VATRATE', $letter.'-'.number_format((float) ($rates[$letter] ?? 0), 2, '.', ''))
                .Xml::tag('NETTAMOUNT', Money::format($bucket['net']))
                .Xml::tag('TAXAMOUNT', Money::format($bucket['tax']));
        }
        $xml .= '</VATTOTALS>';

        $xml .= '<PAYMENTS>';
        foreach ($summary['payments'] as $type => $cents) {
            $xml .= Xml::tag('PMTTYPE', $type).Xml::tag('PMTAMOUNT', Money::format($cents));
        }
        $xml .= '</PAYMENTS>';

        return $xml
            .'<CHANGES><VATCHANGENUM>0</VATCHANGENUM><HEADCHANGENUM>0</HEADCHANGENUM></CHANGES>'
            .'<ERRORS></ERRORS>'
            .'<FWVERSION>3.0</FWVERSION>'
            .'<FWCHECKSUM>WEBAPI</FWCHECKSUM>'
            .'</ZREPORT>';
    }
}
