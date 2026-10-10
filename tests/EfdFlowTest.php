<?php

namespace OnlineEfd\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use OnlineEfd\Data\Customer;
use OnlineEfd\Data\ReceiptData;
use OnlineEfd\Data\ReceiptItem;
use OnlineEfd\Enums\CustomerIdType;
use OnlineEfd\Enums\PaymentType;
use OnlineEfd\Exceptions\EfdException;
use OnlineEfd\Facades\Efd;
use OnlineEfd\Models\EfdProfile;
use OnlineEfd\Models\EfdReceipt;
use OnlineEfd\Support\Certificate;

class EfdFlowTest extends TestCase
{
    private const REGISTRATION_OK = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<EFDMS><EFDMSRESP><ACKCODE>0</ACKCODE><ACKMSG>Registration Successful</ACKMSG><REGID>TZ0100000000</REGID><SERIAL>10TXXXXX73</SERIAL><UIN>UIN-1</UIN><TIN>150000091</TIN><VRN>NOT REGISTERED</VRN><MOBILE>0700000000</MOBILE><ADDRESS>1234</ADDRESS><STREET>MAIN STREET</STREET><CITY>Dar es Salaam</CITY><COUNTRY>TANZANIA</COUNTRY><NAME>ACME LTD </NAME><RECEIPTCODE>A1B2C3</RECEIPTCODE><REGION>Region</REGION><ROUTINGKEY>vfdrct</ROUTINGKEY><GC>4</GC><TAXOFFICE>Tax Office</TAXOFFICE><USERNAME>tra-user</USERNAME><PASSWORD>tra+pass;</PASSWORD><TOKENPATH>vfdtoken</TOKENPATH><TAXCODES><CODEA>18</CODEA><CODEB>0</CODEB><CODEC>0</CODEC><CODED>0</CODED></TAXCODES></EFDMSRESP><EFDMSSIGNATURE>x</EFDMSSIGNATURE></EFDMS>
XML;

    private function registeredShop(): array
    {
        Http::fake([
            '*/api/vfdRegReq' => Http::response(self::REGISTRATION_OK, 200),
            '*/vfdtoken' => Http::response(['access_token' => 'tok-123', 'token_type' => 'bearer', 'expires_in' => 3600]),
        ]);

        $shop = $this->makeShop();
        $profile = new EfdProfile(['owner_type' => $shop->getMorphClass(), 'owner_id' => $shop->id, 'tin' => '150000091', 'cert_key' => '10TXXXXX73']);
        $profile->setCertificate($this->makePfx('pw'), 'pw');
        $profile->save();

        Efd::for($shop)->register();

        return [$shop, $profile->fresh()];
    }

    private function sample(): ReceiptData
    {
        return new ReceiptData(
            [new ReceiptItem('Entry ticket', 2, 59, id: 'T1')],
            PaymentType::Cash,
            new Customer('Jane & Co', '255700000000', CustomerIdType::NationalId, '00000000000000000000'),
        );
    }

    public function test_certificate_signs_and_rejects_wrong_password(): void
    {
        $pfx = $this->makePfx('pw');
        $cert = Certificate::fromPkcs12($pfx, 'pw');

        $this->assertNotSame('', $cert->sign('<x/>'));
        $this->assertSame(base64_encode($cert->serialHex), $cert->serialHeader());

        $this->expectException(EfdException::class);
        Certificate::fromPkcs12($pfx, 'wrong');
    }

    public function test_registration_stores_credentials_encrypted_and_not_in_raw_info(): void
    {
        [, $profile] = $this->registeredShop();

        $this->assertTrue($profile->isRegistered());
        $this->assertSame('tra-user', $profile->username);
        $this->assertSame('ACME LTD', $profile->name);
        $this->assertSame(4, $profile->gc);
        $this->assertArrayNotHasKey('PASSWORD', $profile->registration_info);

        $raw = \DB::table('efd_profiles')->first();
        $this->assertStringNotContainsString('tra-user', $raw->username);
        $this->assertStringNotContainsString('tra+pass;', $raw->password);
        $this->assertStringNotContainsString('pw', (string) $raw->certificate_password);
    }

    public function test_issue_receipt_signs_posts_and_advances_counters(): void
    {
        [$shop] = $this->registeredShop();
        Http::fake([
            '*/api/efdmsRctInfo' => Http::response('<?xml version="1.0"?><EFDMS><RCTACK><RCTNUM>5</RCTNUM><ACKCODE>0</ACKCODE><ACKMSG>Success</ACKMSG></RCTACK></EFDMS>'),
            '*/vfdtoken' => Http::response(['access_token' => 'tok-123', 'token_type' => 'bearer', 'expires_in' => 3600]),
        ]);

        $receipt = $shop->efd()->issueReceipt($this->sample(), $shop);

        $this->assertTrue($receipt->isPosted());
        $this->assertSame(5, $receipt->gc);          // registration GC was 4
        $this->assertSame(1, $receipt->dc);
        $this->assertSame('118.00', $receipt->total_incl);
        $this->assertStringContainsString('A1B2C3'.$receipt->gc.'_', $receipt->verification_url);
        $this->assertStringContainsString('<CUSTNAME>Jane   Co</CUSTNAME>', $receipt->request_xml);

        Http::assertSent(function (Request $r) {
            return str_ends_with($r->url(), '/api/efdmsRctInfo')
                && $r->hasHeader('Authorization', 'Bearer tok-123')
                && $r->hasHeader('Routing-Key', 'vfdrct')
                && $r->hasHeader('Client', 'WEBAPI')
                && str_contains($r->body(), '<EFDMSSIGNATURE>');
        });

        // The same sale never produces a second receipt.
        $again = $shop->efd()->issueReceipt($this->sample(), $shop);
        $this->assertSame($receipt->id, $again->id);
        $this->assertSame(1, EfdReceipt::count());
    }

    public function test_rejected_receipt_is_kept_failed_and_can_be_retried_with_same_counters(): void
    {
        [$shop] = $this->registeredShop();
        Http::fake([
            '*/vfdtoken' => Http::response(['access_token' => 'tok-123', 'token_type' => 'bearer', 'expires_in' => 3600]),
            '*/api/efdmsRctInfo' => Http::sequence()
                ->push('<EFDMS><RCTACK><ACKCODE>31</ACKCODE><ACKMSG>Bad signature</ACKMSG></RCTACK></EFDMS>')
                ->push('<EFDMS><RCTACK><ACKCODE>0</ACKCODE><ACKMSG>Success</ACKMSG></RCTACK></EFDMS>'),
        ]);

        try {
            $shop->efd()->issueReceipt($this->sample(), $shop);
            $this->fail('Expected rejection');
        } catch (EfdException $e) {
            $this->assertStringContainsString('Bad signature', $e->getMessage());
            $this->assertSame('31', $e->receipt->ack_code);   // the failed receipt travels with the exception
        }

        $failed = EfdReceipt::firstOrFail();
        $this->assertSame(EfdReceipt::FAILED, $failed->status);

        $retried = $shop->efd()->retry($failed);
        $this->assertTrue($retried->isPosted());
        $this->assertSame($failed->gc, $retried->gc);
        $this->assertSame(2, $retried->attempts);
    }

    public function test_z_report_totals_and_blocks_while_receipts_are_unposted(): void
    {
        [$shop] = $this->registeredShop();
        Http::fake([
            '*/vfdtoken' => Http::response(['access_token' => 'tok-123', 'token_type' => 'bearer', 'expires_in' => 3600]),
            '*/api/efdmsRctInfo' => Http::response('<EFDMS><RCTACK><ACKCODE>0</ACKCODE><ACKMSG>Success</ACKMSG></RCTACK></EFDMS>'),
            '*/api/efdmszreport' => Http::response('<EFDMS><ZACK><ACKCODE>0</ACKCODE><ACKMSG>Success</ACKMSG></ZACK></EFDMS>'),
        ]);

        $shop->efd()->issueReceipt($this->sample());
        $shop->efd()->issueReceipt($this->sample());

        $report = $shop->efd()->postZReport();

        $this->assertSame('posted', $report->status);
        $this->assertSame('236.00', $report->daily_total);
        $this->assertSame(2, $report->tickets_fiscal);
        $this->assertStringContainsString('<PMTTYPE>CASH</PMTTYPE><PMTAMOUNT>236.00</PMTAMOUNT>', $report->request_xml);
        $this->assertStringContainsString('<VATRATE>A-18.00</VATRATE><NETTAMOUNT>200.00</NETTAMOUNT><TAXAMOUNT>36.00</TAXAMOUNT>', $report->request_xml);
        $this->assertSame('236.00', EfdProfile::first()->gross);

        // Posting the same day again is a no-op.
        $this->assertSame($report->id, $shop->efd()->postZReport()->id);
    }

    public function test_test_receipts_use_counters_but_stay_out_of_z_reports(): void
    {
        [$shop] = $this->registeredShop();
        Http::fake([
            '*/vfdtoken' => Http::response(['access_token' => 'tok-123', 'token_type' => 'bearer', 'expires_in' => 3600]),
            '*/api/efdmsRctInfo' => Http::sequence()
                ->push('<EFDMS><RCTACK><ACKCODE>0</ACKCODE><ACKMSG>Success</ACKMSG></RCTACK></EFDMS>')   // real sale
                ->push('<EFDMS><RCTACK><ACKCODE>0</ACKCODE><ACKMSG>Success</ACKMSG></RCTACK></EFDMS>')   // test
                ->push('<EFDMS><RCTACK><ACKCODE>9</ACKCODE><ACKMSG>Nope</ACKMSG></RCTACK></EFDMS>'),     // failing test
            '*/api/efdmszreport' => Http::response('<EFDMS><ZACK><ACKCODE>0</ACKCODE><ACKMSG>Success</ACKMSG></ZACK></EFDMS>'),
        ]);

        $real = $shop->efd()->issueReceipt($this->sample(), $shop);
        $test = $shop->efd()->issueTestReceipt(['items' => [['description' => 'Test', 'quantity' => 1, 'unit_price' => 1000]]]);

        $this->assertTrue($test->is_test);
        $this->assertTrue($test->isPosted());
        $this->assertSame($real->gc + 1, $test->gc);           // tests consume the next counter

        try {
            $shop->efd()->issueTestReceipt($this->sample());
            $this->fail('Expected rejection');
        } catch (EfdException) {
            $this->assertSame(1, EfdReceipt::where('is_test', true)->where('status', 'failed')->count());
        }

        // The failed test must not block the Z-report, and neither test counts in its totals.
        $report = $shop->efd()->postZReport();
        $this->assertSame('posted', $report->status);
        $this->assertSame('118.00', $report->daily_total);
        $this->assertSame(1, $report->tickets_fiscal);
    }

    public function test_retry_command_resends_failed_real_receipts_but_not_tests(): void
    {
        [$shop] = $this->registeredShop();
        Http::fake([
            '*/vfdtoken' => Http::response(['access_token' => 'tok-123', 'token_type' => 'bearer', 'expires_in' => 3600]),
            '*/api/efdmsRctInfo' => Http::sequence()
                ->push('<EFDMS><RCTACK><ACKCODE>9</ACKCODE><ACKMSG>Down</ACKMSG></RCTACK></EFDMS>')   // real sale fails
                ->push('<EFDMS><RCTACK><ACKCODE>9</ACKCODE><ACKMSG>Down</ACKMSG></RCTACK></EFDMS>')   // test fails
                ->push('<EFDMS><RCTACK><ACKCODE>0</ACKCODE><ACKMSG>Success</ACKMSG></RCTACK></EFDMS>'), // the retry
        ]);

        try { $shop->efd()->issueReceipt($this->sample(), $shop); } catch (EfdException) {}
        try { $shop->efd()->issueTestReceipt($this->sample()); } catch (EfdException) {}

        $this->artisan('efd:retry', ['--minutes' => 0])->assertSuccessful();

        $this->assertSame(1, EfdReceipt::where('status', 'posted')->count());
        $this->assertSame('posted', EfdReceipt::where('is_test', false)->first()->status);
        $this->assertSame('failed', EfdReceipt::where('is_test', true)->first()->status);   // tests are never retried
    }

    public function test_unregistered_profile_cannot_issue(): void
    {
        $shop = $this->makeShop();
        $profile = new EfdProfile(['owner_type' => $shop->getMorphClass(), 'owner_id' => $shop->id, 'tin' => '1', 'cert_key' => 'K']);
        $profile->setCertificate($this->makePfx('pw'), 'pw');
        $profile->save();

        $this->expectException(EfdException::class);
        $shop->efd()->issueReceipt($this->sample());
    }
}
