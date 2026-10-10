# Online EFD

Small Laravel package for the TRA (Tanzania) **VFD/EFD** web API. No UI: your app builds its own
settings page and calls a handful of functions with plain arrays/JSON.

Each *owner* in your app (a show, shop, company…) has their own TIN, certificate key, `.pfx` and
password. The package stores them encrypted and handles registration, tokens, signing, receipts and Z-reports.

Requires PHP 8.1+, `ext-openssl`, `ext-simplexml`, Laravel 10–13. Keep `APP_KEY` stable.

## Install

```bash
composer require ubitechsolutionsltd/online-efd
```

Or, while developing locally against a checkout, point Composer at the folder instead:

```json
"repositories": [{ "type": "path", "url": "../vfd-master/online_efd", "options": { "symlink": true } }]
```
```bash
composer require ubitechsolutionsltd/online-efd:@dev
php artisan migrate
```

Add the trait to the owner model (optional shortcut, you can always use `Efd::for($owner)`):

```php
use OnlineEfd\Concerns\HasEfdProfile;
class Show extends Model { use HasEfdProfile; }
```

## The whole API

```php
use OnlineEfd\Facades\Efd;

// --- settings page -------------------------------------------------------
Efd::configure($show, [                   // save what the owner typed/uploaded (all keys optional after first save)
    'tin' => '150000091',
    'cert_key' => '10TXXXXX73',
    'certificate' => $request->file('certificate'),   // UploadedFile, file path, or raw .pfx bytes
    'certificate_password' => $request->input('certificate_password'),
]);                                       // throws EfdException on wrong password / bad file

$show->efd()->register();                 // register with TRA (stores username/password etc.)
$show->efd()->testConnection();           // proves the stored credentials work
Efd::status($show);                       // JSON-safe array for the page (never contains secrets)
Efd::removeCertificate($show);

// --- at sale time --------------------------------------------------------
$receipt = $show->efd()->issueReceipt($json, $sale);   // $json: JSON string or decoded array
$receipt->verification_url;                            // link/QR for the printed receipt
$show->efd()->retry($receipt);                         // if TRA was down
$show->efd()->issueReceiptLater($json, $sale);         // queue it instead

// --- end of day ----------------------------------------------------------
$show->efd()->postZReport();                           // or schedule: php artisan efd:z-report
```

### Receipt JSON

`unit_price` is tax-inclusive. Everything except `items` is optional.

```json
{
  "items": [
    { "description": "Entry ticket", "quantity": 2, "unit_price": 59000, "tax_code": 1, "id": "T1" },
    { "description": "Booking fee",  "quantity": 1, "unit_price": 5000,  "tax_code": 5 }
  ],
  "payment_type": "EMONEY",
  "customer": { "name": "Jane Doe", "phone": "255700000000", "id_type": 5, "id": "0000000000" },
  "issued_at": "2026-10-10 14:30:00"
}
```

| Field | Values |
|---|---|
| `tax_code` | `1` standard 18% (A, default) · `2` special (B) · `3` zero (C) · `4` special relief (D) · `5` exempt (E) |
| `payment_type` | `CASH` (default) · `CHEQUE` · `CCARD` · `EMONEY` · `INVOICE` |
| `customer.id_type` | `1` TIN · `2` driving licence · `3` voter · `4` passport · `5` national ID · `6` none (default) |

Invalid input throws `InvalidArgumentException` naming the field. TRA/network/certificate problems throw
`OnlineEfd\Exceptions\EfdException` (message is safe to show users). A failed receipt stays stored as
`failed` and can be retried with its original numbers; passing `$sale` makes issuing idempotent per sale.

### Example settings controller

```php
public function save(Request $request, Show $show)
{
    $this->authorize('update', $show);

    try {
        Efd::configure($show, $request->only(['tin', 'cert_key', 'certificate', 'certificate_password']));
        if ($request->boolean('register')) {
            $show->efd()->register();
        }
    } catch (EfdException $e) {
        return back()->withErrors(['efd' => $e->getMessage()]);
    }

    return back()->with('efd', Efd::status($show));
}
```

## Config (`php artisan vendor:publish --tag=efd-config`)

| Key | Purpose |
|---|---|
| `api.base_url`, `api.endpoints.*` | TRA endpoints (`EFD_BASE_URL`) |
| `api.verify_ssl` | TLS verification, on by default (`EFD_VERIFY_SSL`) |
| `timezone` | Receipt time, Z-number, daily counter reset (default `Africa/Dar_es_Salaam`) |
| `tax_rates` | Fallback VAT %; rates returned by TRA at registration take precedence |
| `queue.*` | Connection / queue / tries / backoff for `issueReceiptLater()` |

Events: `OnlineEfd\Events\ReceiptPosted`, `ReceiptFailed`.

## Troubleshooting

- **"could not open the .pfx"**: wrong password, or a legacy-encrypted file on OpenSSL 3. Re-export:
  `openssl pkcs12 -legacy -in old.pfx -nodes -out tmp.pem && openssl pkcs12 -export -in tmp.pem -out new.pfx`.
- **cURL error 60**: fix the host's CA bundle rather than disabling `verify_ssl`.

## Tests

```bash
composer install && vendor/bin/phpunit
```

Full developer documentation: `online_efd_developer_documentation.pdf` (regenerate with `python docs/build_documentation.py`).
