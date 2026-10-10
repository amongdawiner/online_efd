<?php

namespace OnlineEfd\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use OnlineEfd\Data\ReceiptData;
use OnlineEfd\Enums\PaymentType;
use OnlineEfd\Enums\TaxCode;
use OnlineEfd\Exceptions\EfdException;
use OnlineEfd\Facades\Efd;

class SettingsAndJsonTest extends TestCase
{
    private function registrationXml(): string
    {
        return '<EFDMS><EFDMSRESP><ACKCODE>0</ACKCODE><ACKMSG>OK</ACKMSG><REGID>TZ1</REGID><SERIAL>10TZ1</SERIAL><UIN>U</UIN><VRN>V</VRN>'
            .'<NAME>ACME</NAME><RECEIPTCODE>ABC123</RECEIPTCODE><ROUTINGKEY>vfdrct</ROUTINGKEY><GC>0</GC><TAXOFFICE>TO</TAXOFFICE>'
            .'<USERNAME>u</USERNAME><PASSWORD>p</PASSWORD><TAXCODES><CODEA>18</CODEA><CODEB>0</CODEB><CODEC>0</CODEC><CODED>0</CODED></TAXCODES></EFDMSRESP></EFDMS>';
    }

    public function test_settings_page_flow_configure_register_status(): void
    {
        Http::fake(['*/api/vfdRegReq' => Http::response($this->registrationXml())]);
        $shop = $this->makeShop();

        $this->assertFalse(Efd::status($shop)['configured']);

        Efd::configure($shop, [
            'tin' => '150000091',
            'cert_key' => '10TZ1',
            'certificate' => $this->makePfx('pw'),   // raw bytes (a path or UploadedFile also works)
            'certificate_password' => 'pw',
        ]);
        $shop->efd()->register();

        $status = Efd::status($shop);
        $this->assertTrue($status['ready']);
        $this->assertSame('ACME', $status['taxpayer']['name']);
        $this->assertNotNull($status['certificate_expires_at']);
        $this->assertStringNotContainsString('pw', json_encode($status));
        $this->assertStringNotContainsString('"p"', json_encode($status));
    }

    public function test_configure_accepts_a_file_path_and_rejects_wrong_password(): void
    {
        $shop = $this->makeShop();
        $path = tempnam(sys_get_temp_dir(), 'pfx');
        file_put_contents($path, $this->makePfx('right'));

        try {
            Efd::configure($shop, ['tin' => '1', 'cert_key' => 'K', 'certificate' => $path, 'certificate_password' => 'right']);
            $this->assertTrue(Efd::status($shop)['has_certificate']);

            $this->expectException(EfdException::class);
            Efd::configure($shop, ['certificate' => $path, 'certificate_password' => 'wrong']);
        } finally {
            @unlink($path);
        }
    }

    public function test_changing_tin_requires_registering_again_and_partial_update_keeps_certificate(): void
    {
        Http::fake(['*/api/vfdRegReq' => Http::response($this->registrationXml())]);
        $shop = $this->makeShop();
        Efd::configure($shop, ['tin' => '1', 'cert_key' => 'K', 'certificate' => $this->makePfx('pw'), 'certificate_password' => 'pw']);
        $shop->efd()->register();
        $this->assertTrue(Efd::status($shop)['registered']);

        Efd::configure($shop, ['tin' => '2']);   // certificate untouched

        $status = Efd::status($shop);
        $this->assertTrue($status['has_certificate']);
        $this->assertFalse($status['registered']);
    }

    public function test_remove_certificate(): void
    {
        $shop = $this->makeShop();
        Efd::configure($shop, ['tin' => '1', 'cert_key' => 'K', 'certificate' => $this->makePfx('pw'), 'certificate_password' => 'pw']);

        Efd::removeCertificate($shop);

        $this->assertFalse(Efd::status($shop)['has_certificate']);
        $this->assertNull(DB::table('efd_profiles')->value('certificate'));
    }

    public function test_certificate_requires_password(): void
    {
        $this->expectException(EfdException::class);
        Efd::configure($this->makeShop(), ['tin' => '1', 'cert_key' => 'K', 'certificate' => $this->makePfx('pw')]);
    }

    public function test_issue_receipt_from_json_string(): void
    {
        Http::fake([
            '*/api/vfdRegReq' => Http::response($this->registrationXml()),
            '*/vfdtoken' => Http::response(['access_token' => 't', 'token_type' => 'bearer', 'expires_in' => 3600]),
            '*/api/efdmsRctInfo' => Http::response('<EFDMS><RCTACK><ACKCODE>0</ACKCODE><ACKMSG>Success</ACKMSG></RCTACK></EFDMS>'),
        ]);
        $shop = $this->makeShop();
        Efd::configure($shop, ['tin' => '1', 'cert_key' => 'K', 'certificate' => $this->makePfx('pw'), 'certificate_password' => 'pw']);
        $shop->efd()->register();

        $json = json_encode([
            'items' => [
                ['description' => 'Ticket', 'quantity' => 2, 'unit_price' => 59, 'id' => 'T1'],
                ['description' => 'Fee', 'quantity' => 1, 'unit_price' => '10.00', 'tax_code' => '5'],
            ],
            'payment_type' => 'EMONEY',
            'customer' => ['name' => 'Jane', 'phone' => '255700000000', 'id_type' => 5, 'id' => '123'],
        ]);

        $receipt = $shop->efd()->issueReceipt($json, $shop);

        $this->assertTrue($receipt->isPosted());
        $this->assertSame('128.00', $receipt->total_incl);
        $this->assertSame('EMONEY', $receipt->payment_type);
        $this->assertJson($receipt->toJson());   // receipts serialise straight to JSON for API responses
    }

    public function test_from_array_parses_enums_and_validates(): void
    {
        $data = ReceiptData::fromArray([
            'items' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 5, 'tax_code' => 3]],
            'payment_type' => 'CCARD',
        ]);
        $this->assertSame(TaxCode::Zero, $data->items[0]->taxCode);
        $this->assertSame(PaymentType::CreditCard, $data->paymentType);

        foreach ([
            ['items' => [['description' => 'X', 'quantity' => 1]]],                                        // missing unit_price
            ['items' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 1]], 'payment_type' => 'BITCOIN'],
            ['items' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 1, 'tax_code' => 'abc']]],
        ] as $bad) {
            try {
                ReceiptData::fromArray($bad);
                $this->fail('Expected InvalidArgumentException');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(InvalidArgumentException::class);
        ReceiptData::from('not json');
    }
}
