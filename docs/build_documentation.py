"""
Builds ../online_efd_developer_documentation.pdf  (salesmanager theme)

Workflow when the package changes:
  1. Edit the content below, add a CHANGELOG entry at the top, bump VERSION.
  2. Run:  python docs/build_documentation.py

Brand: colours and logo come from the salesmanager app
  (resources/css/genesis.css, public/images/logo.png).
"""
from pathlib import Path

from reportlab.graphics.shapes import Drawing, Polygon, Rect, String
from reportlab.lib import colors
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.lib.units import mm
from reportlab.platypus import (
    HRFlowable, KeepTogether, PageBreak, Paragraph, Preformatted,
    SimpleDocTemplate, Spacer, Table, TableStyle,
)

VERSION = "0.4.3"

# Newest first. Add a new entry for every improvement.
CHANGELOG = [
    ("0.4.3", "2026-10-10", [
        "Composer package renamed to ubitechsolutionsltd/online-efd (the PHP namespace stays OnlineEfd).",
    ]),
    ("0.4.2", "2026-10-10", [
        "Sample data in the README, guide and tests replaced with placeholders (no real TIN, certificate key, registration or contact details).",
    ]),
    ("0.4.1", "2026-10-10", [
        "salesmanager prints the receipt number, verification code and QR on the invoice page, the POS slip and the invoice PDF.",
    ]),
    ("0.4.0", "2026-10-10", [
        "New command efd:retry: re-sends real (non-test) receipts TRA has not accepted, with their original numbers. Schedule it every few minutes.",
        "salesmanager now issues a receipt automatically when an invoice is paid in full (per-business switch, off by default).",
    ]),
    ("0.3.0", "2026-10-10", [
        "Test receipts: issueTestReceipt() sends a receipt flagged is_test. TRA still receives it and it uses the next counters, but it is kept out of Z-reports and never blocks one.",
        "EfdException now carries the stored receipt ($e->receipt) so callers can log rejected receipts.",
        "Integrated into salesmanager: EFD add-on, shared/own details per business, settings page and per-business test area.",
    ]),
    ("0.2.1", "2026-10-10", [
        "Documentation redesigned: salesmanager theme and logo, shorter, step-by-step usage flow with Laravel and PHP samples.",
    ]),
    ("0.2.0", "2026-10-10", [
        "Simplified: removed Livewire, Blade views and Gate-based authorization. The package is UI-free.",
        "Added Efd::configure(), Efd::status(), Efd::removeCertificate().",
        "Receipts can be issued from a JSON string or array, with field validation.",
        "18 tests passing.",
    ]),
    ("0.1.0", "2026-10-10", [
        "Initial package: profiles, registration, token cache, receipts, Z-reports, queue job, events, Artisan command.",
    ]),
]

HERE = Path(__file__).resolve().parent
OUT = HERE.parent / "online_efd_developer_documentation.pdf"
LOGO = HERE / "assets" / "salesmanager-logo.png"

# ---- salesmanager palette (genesis.css) -----------------------------------
NAVY = colors.HexColor("#0F172A")       # primary
EMERALD = colors.HexColor("#059669")    # secondary
SLATE = colors.HexColor("#64748B")      # neutral
TEXT2 = colors.HexColor("#475569")      # text-secondary
BG = colors.HexColor("#F8FAFC")         # background
BORDER = colors.HexColor("#E2E8F0")
EMERALD_TINT = colors.HexColor("#ECFDF5")
AMBER_TINT = colors.HexColor("#FEF9C3")

ss = getSampleStyleSheet()
BODY = ParagraphStyle("Body", parent=ss["Normal"], fontSize=10, leading=14.5, textColor=NAVY, spaceAfter=6)
H1 = ParagraphStyle("H1", parent=BODY, fontName="Helvetica-Bold", fontSize=19, leading=23, spaceBefore=6, spaceAfter=2, keepWithNext=1)
H2 = ParagraphStyle("H2", parent=BODY, fontName="Helvetica-Bold", fontSize=12, leading=16, spaceBefore=10, spaceAfter=3, textColor=EMERALD, keepWithNext=1)
BULLET = ParagraphStyle("Bullet", parent=BODY, leftIndent=14, bulletIndent=3, spaceAfter=2)
CODE = ParagraphStyle("Code", parent=ss["Code"], fontSize=7.8, leading=10.2, textColor=NAVY, backColor=BG,
                      borderColor=BORDER, borderWidth=0.6, borderPadding=(6, 7, 6, 7), leftIndent=7, rightIndent=7,
                      spaceBefore=6, spaceAfter=12)
CELL = ParagraphStyle("Cell", parent=BODY, fontSize=8.8, leading=11.5, spaceAfter=0)
CELLH = ParagraphStyle("CellH", parent=CELL, fontName="Helvetica-Bold", textColor=colors.white)
CALL = ParagraphStyle("Call", parent=BODY, fontSize=9.4, leading=13, borderPadding=7, leftIndent=8, rightIndent=8,
                      spaceBefore=6, spaceAfter=12)

story = []


def h1(t):
    story.append(Paragraph(t, H1))
    rule = HRFlowable(width=22 * mm, thickness=2.2, color=EMERALD, spaceAfter=8, hAlign="LEFT")
    rule.keepWithNext = True
    story.append(rule)


def h2(t): story.append(Paragraph(t, H2))
def p(t): story.append(Paragraph(t, BODY))
def code(t): story.append(Preformatted(t.strip("\n"), CODE))


def tip(t):
    story.append(Paragraph("<b>Tip.</b> " + t, ParagraphStyle("t", parent=CALL, backColor=EMERALD_TINT)))


def note(t):
    story.append(Paragraph("<b>Note.</b> " + t, ParagraphStyle("n", parent=CALL, backColor=AMBER_TINT)))


def bullets(items):
    for i in items:
        story.append(Paragraph(i, BULLET, bulletText="\u2022"))
    story.append(Spacer(1, 5))


def table(rows, widths, mono_first=False):
    data = []
    for r, row in enumerate(rows):
        cells = []
        for c, val in enumerate(row):
            if r == 0:
                cells.append(Paragraph(str(val), CELLH))
            elif c == 0 and mono_first:
                cells.append(Paragraph(f"<font face='Courier' size='8.2'>{val}</font>", CELL))
            else:
                cells.append(Paragraph(str(val), CELL))
        data.append(cells)
    t = Table(data, colWidths=[w * mm for w in widths], repeatRows=1)
    t.setStyle(TableStyle([
        ("VALIGN", (0, 0), (-1, -1), "TOP"),
        ("BACKGROUND", (0, 0), (-1, 0), NAVY),
        ("ROWBACKGROUNDS", (0, 1), (-1, -1), [colors.white, BG]),
        ("LINEBELOW", (0, 0), (-1, -1), 0.4, BORDER),
        ("TOPPADDING", (0, 0), (-1, -1), 4), ("BOTTOMPADDING", (0, 0), (-1, -1), 4),
        ("LEFTPADDING", (0, 0), (-1, -1), 6),
    ]))
    story.append(t)
    story.append(Spacer(1, 10))


def step_heading(n, title):
    d = Drawing(170 * mm, 9 * mm)
    d.add(Rect(0, 0.5 * mm, 8 * mm, 8 * mm, rx=2 * mm, ry=2 * mm, fillColor=EMERALD, strokeColor=EMERALD))
    d.add(String(4 * mm, 3 * mm, str(n), fontName="Helvetica-Bold", fontSize=11, fillColor=colors.white, textAnchor="middle"))
    d.add(String(11.5 * mm, 3 * mm, title, fontName="Helvetica-Bold", fontSize=12.5, fillColor=NAVY))
    wrap = Table([[d]], colWidths=[170 * mm])
    wrap.setStyle(TableStyle([("LEFTPADDING", (0, 0), (-1, -1), 0), ("RIGHTPADDING", (0, 0), (-1, -1), 0),
                              ("TOPPADDING", (0, 0), (-1, -1), 0), ("BOTTOMPADDING", (0, 0), (-1, -1), 3)]))
    wrap.keepWithNext = True
    story.append(Spacer(1, 6))
    story.append(wrap)


def flow(steps):
    """Horizontal process diagram: list of (title, subtitle) tuples."""
    n = len(steps)
    total, gap = 170 * mm, 5 * mm
    w = (total - gap * (n - 1)) / n
    h = 26 * mm
    d = Drawing(total, h + 4 * mm)
    for i, (title, sub) in enumerate(steps):
        x = i * (w + gap)
        d.add(Rect(x, 2 * mm, w, h, rx=2.5 * mm, ry=2.5 * mm, fillColor=BG if i else NAVY,
                   strokeColor=BORDER if i else NAVY, strokeWidth=0.8))
        d.add(Rect(x, 2 * mm + h - 2.2 * mm, w, 2.2 * mm, fillColor=EMERALD, strokeColor=EMERALD))
        fg = NAVY if i else colors.white
        d.add(String(x + w / 2, 2 * mm + h - 8 * mm, f"{i + 1}", fontName="Helvetica-Bold", fontSize=11,
                     fillColor=EMERALD if i else colors.white, textAnchor="middle"))
        for k, line in enumerate(title.split("\n")):
            d.add(String(x + w / 2, 2 * mm + h - 13.5 * mm - k * 3.6 * mm, line, fontName="Helvetica-Bold",
                         fontSize=8.3, fillColor=fg, textAnchor="middle"))
        for k, line in enumerate(sub.split("\n")):
            d.add(String(x + w / 2, 5.6 * mm - k * 3 * mm + 3 * mm, line, fontName="Helvetica", fontSize=6.8,
                         fillColor=SLATE if i else colors.HexColor("#CBD5E1"), textAnchor="middle"))
        if i < n - 1:
            ax = x + w + 0.8 * mm
            cy = 2 * mm + h / 2
            d.add(Polygon([ax, cy + 1.8 * mm, ax, cy - 1.8 * mm, ax + gap - 1.6 * mm, cy],
                          fillColor=EMERALD, strokeColor=EMERALD))
    story.append(d)
    story.append(Spacer(1, 6))


# =========================================================== cover
story += [Spacer(1, 78 * mm),
          Paragraph("Online EFD", ParagraphStyle("T", parent=BODY, fontName="Helvetica-Bold", fontSize=34, leading=38)),
          Paragraph("Developer Guide", ParagraphStyle("S", parent=BODY, fontSize=17, leading=22, textColor=EMERALD)),
          Spacer(1, 6 * mm),
          Paragraph("Issue TRA-compliant fiscal receipts from salesmanager (or any Laravel app). Each business enters "
                    "its own TRA details once on its settings page; your code then sends a receipt as JSON.",
                    ParagraphStyle("D", parent=BODY, fontSize=11, leading=16, textColor=TEXT2)),
          Spacer(1, 10 * mm),
          Paragraph(f"Version <b>{VERSION}</b> &nbsp;|&nbsp; Updated <b>{CHANGELOG[0][1]}</b> &nbsp;|&nbsp; "
                    "Composer: <font face='Courier'>ubitechsolutionsltd/online-efd</font>",
                    ParagraphStyle("M", parent=BODY, fontSize=9, textColor=SLATE)),
          PageBreak()]

# =========================================================== 1 overview
h1("1. How it works")
p("The package does the hard TRA work (certificate signing, registration, tokens, receipt XML, counters, retries, "
  "Z-reports). You only build two things in salesmanager: a <b>settings page</b> and one call at <b>checkout</b>.")
flow([
    ("Settings page", "business enters TIN,\ncertificate, password"),
    ("Register", "Efd::configure()\n+ efd()->register()"),
    ("Checkout", "issueReceipt(json,\n$invoice)"),
    ("Print", "show verification\nlink / QR"),
    ("End of day", "efd:z-report\n(scheduled)"),
])
bullets([
    "<b>Multi-tenant:</b> every salesmanager <font face='Courier'>Business</font> has its own TIN, certificate, TRA login and counters.",
    "<b>JSON in, JSON out:</b> send an array or JSON string, get a receipt record that serialises to JSON.",
    "<b>Safe:</b> certificate, passwords and tokens are encrypted in the database and never returned by the package.",
    "<b>Forgiving:</b> if TRA is down the receipt is kept and can be retried with the same numbers. The same invoice never gets two receipts.",
])
note("Keep <font face='Courier'>APP_KEY</font> stable. Stored certificates are encrypted with it and become unreadable if it changes.")

# =========================================================== 2 install
h1("2. Install (5 minutes)")
step_heading(1, "Add the package")
p("While developing locally, point salesmanager's <font face='Courier'>composer.json</font> at the folder:")
code("""
"repositories": [
  { "type": "path", "url": "../vfd-master/online_efd", "options": { "symlink": true } }
]
""")
code("""
composer require ubitechsolutionsltd/online-efd:@dev
php artisan migrate        # creates efd_profiles, efd_receipts, efd_z_reports
""")
step_heading(2, "Mark the tenant model")
p("In salesmanager the tenant is <font face='Courier'>Business</font> and the sale is <font face='Courier'>Invoice</font>.")
code("""
// app/Models/Business.php
use OnlineEfd\\Concerns\\HasEfdProfile;

class Business extends Model
{
    use HasEfdProfile;      // gives $business->efd() and $business->hasEfd()
    // ...
}
""")
p("Optional: <font face='Courier'>php artisan vendor:publish --tag=efd-config</font> to change endpoints, timezone or queue settings.")

# =========================================================== 3 flow
story.append(PageBreak())
h1("3. Real usage flow in salesmanager")
p("Adapt model, route and relation names to your code. Everything below uses the package's real functions.")

step_heading(1, "Settings page: the business saves its TRA details")
p("Four fields: TIN, certificate key (e.g. <font face='Courier'>10TXXXXX73</font>), the <font face='Courier'>.pfx</font> "
  "file and its password. These come from TRA when the business is approved for VFD.")
code("""
// routes/web.php
Route::middleware('auth')->group(function () {
    Route::get('/settings/efd',           [EfdSettingsController::class, 'edit'])->name('efd.edit');
    Route::post('/settings/efd',          [EfdSettingsController::class, 'update'])->name('efd.update');
    Route::post('/settings/efd/register', [EfdSettingsController::class, 'register'])->name('efd.register');
});
""")
code("""
// app/Http/Controllers/EfdSettingsController.php
use OnlineEfd\\Exceptions\\EfdException;
use OnlineEfd\\Facades\\Efd;

class EfdSettingsController extends Controller
{
    private function business(Request $request): Business
    {
        // Always resolve from the logged-in user, never from a request id.
        return $request->user()->business;           // however salesmanager resolves it
    }

    public function edit(Request $request)
    {
        return view('settings.efd', ['efd' => Efd::status($this->business($request))]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'tin'                  => ['required', 'string', 'max:20'],
            'cert_key'             => ['required', 'string', 'max:50'],
            'certificate'          => ['nullable', 'file', 'max:256'],
            'certificate_password' => ['nullable', 'string'],
        ]);

        try {
            Efd::configure($this->business($request), $data);   // opens the .pfx to verify it
        } catch (EfdException $e) {
            return back()->withErrors(['efd' => $e->getMessage()])->withInput();
        }

        return back()->with('status', 'EFD settings saved.');
    }

    public function register(Request $request)
    {
        try {
            $this->business($request)->efd()->register();   // talks to TRA
        } catch (EfdException $e) {
            return back()->withErrors(['efd' => $e->getMessage()]);
        }

        return back()->with('status', 'Registered with TRA.');
    }
}
""")
code("""
{{-- resources/views/settings/efd.blade.php --}}
<form method="POST" action="{{ route('efd.update') }}" enctype="multipart/form-data">
    @csrf
    <input name="tin"      value="{{ old('tin', $efd['tin']) }}"           placeholder="TIN">
    <input name="cert_key" value="{{ old('cert_key', $efd['cert_key']) }}" placeholder="Certificate key">
    <input type="file"     name="certificate" accept=".pfx,.p12">
    <input type="password" name="certificate_password" placeholder="Certificate password">
    <button>Save</button>
</form>

@if ($efd['has_certificate'])
    Certificate expires {{ \\Carbon\\Carbon::parse($efd['certificate_expires_at'])->toFormattedDateString() }}
@endif

@if ($efd['registered'])
    Registered: {{ $efd['taxpayer']['name'] }} (VRN {{ $efd['taxpayer']['vrn'] }})
@elseif ($efd['has_certificate'])
    <form method="POST" action="{{ route('efd.register') }}">@csrf<button>Register with TRA</button></form>
@endif
""")
tip("<font face='Courier'>Efd::status()</font> is safe to send to the page or an API: it never contains the certificate, "
    "passwords or tokens. Fields you leave empty on later saves are kept as they are.")

step_heading(2, "Checkout: issue the receipt when an invoice is paid")
p("Map the invoice to the receipt JSON and call <font face='Courier'>issueReceipt()</font>. Passing the invoice as the "
  "second argument means calling it twice for the same invoice returns the same receipt.")
code("""
// app/Services/FiscalReceiptService.php
use OnlineEfd\\Exceptions\\EfdException;
use OnlineEfd\\Facades\\Efd;

class FiscalReceiptService
{
    public function issueFor(Invoice $invoice): ?\\OnlineEfd\\Models\\EfdReceipt
    {
        $business = Business::findOrFail($invoice->business_id);

        if (! Efd::isReady($business)) {
            return null;                              // EFD not set up: sell as usual
        }

        $payload = [
            'items' => $invoice->invoiceProducts->map(fn ($line) => [
                'id'          => (string) $line->product_id,
                'description' => $line->product->name,
                'quantity'    => $line->quantity,
                'unit_price'  => $line->sale_price,   // must be the final, VAT-inclusive price
                'tax_code'    => 1,                   // 1 = 18% VAT, 5 = exempt (see section 4)
            ])->all(),
            'payment_type' => 'CASH',                 // CASH | CHEQUE | CCARD | EMONEY | INVOICE
            'customer'     => $invoice->client ? [
                'name'  => $invoice->client->name,
                'phone' => $invoice->client->phone,
            ] : null,
        ];

        try {
            return $business->efd()->issueReceipt($payload, $invoice);
        } catch (EfdException $e) {
            report($e);        // receipt is saved as "failed" and can be retried (step 4)
            return null;       // do not block the sale because TRA is unreachable
        }
    }
}
""")
code("""
// where the invoice is finalised
$receipt = app(FiscalReceiptService::class)->issueFor($invoice);

$receipt?->verification_url;   // https://verify.tra.go.tz/...  (print it, or turn it into a QR)
$receipt?->rctnum;             // fiscal receipt number to print on the invoice
""")
p("<b>Prefer not to wait for TRA at checkout?</b> Use the queue instead. The receipt is reserved immediately and "
  "posted in the background, with automatic retries:")
code("""
$business->efd()->issueReceiptLater($payload, $invoice);   // needs a running queue worker
""")

step_heading(3, "Same thing over JSON (API or mobile/POS clients)")
p("The receipt payload is plain JSON, so it works the same from an API endpoint:")
code("""
// routes/api.php
Route::post('/invoices/{invoice}/efd', function (Request $request, Invoice $invoice) {
    $business = $request->user()->business;
    abort_unless($invoice->business_id === $business->id, 403);

    return response()->json(
        $business->efd()->issueReceipt($request->all(), $invoice)    // also accepts a JSON string
    );
});
""")
code("""
curl -X POST https://app.example.com/api/invoices/1042/efd \\
  -H "Authorization: Bearer <token>" -H "Content-Type: application/json" \\
  -d '{"items":[{"description":"Soda 500ml","quantity":3,"unit_price":1500},
                {"description":"Bread","quantity":1,"unit_price":2000,"tax_code":5}],
       "payment_type":"CASH"}'
""")
code("""
{ "id": 87, "rctnum": 14, "gc": 14, "dc": 3, "znum": "20261010",
  "total_excl": "5813.56", "total_incl": "6500.00", "payment_type": "CASH",
  "status": "posted", "ack_code": "0", "ack_message": "Success",
  "verification_url": "https://verify.tra.go.tz/A1B2C314_143055",
  "posted_at": "2026-10-10T14:30:56.000000Z", "items": [ ... ], "vat_totals": { "A": { ... } } }
""")

step_heading(4, "When TRA was unreachable: retry failed receipts")
p("A failed receipt keeps its receipt number and counters, so retrying never leaves a gap. The package ships a "
  "command that re-sends every real receipt TRA has not accepted yet (test receipts are never retried). Schedule it "
  "every few minutes, and it also clears the way for the Z-report:")
code("""
// routes/console.php (Laravel 11+)  or  app/Console/Kernel.php::schedule() (Laravel 10)
Schedule::command('efd:retry')->everyFiveMinutes()->withoutOverlapping();

// by hand
php artisan efd:retry --limit=50 --minutes=2
""")

step_heading(5, "End of day: Z-report")
p("TRA expects one Z-report per business per day, after the last sale. The command posts it for every registered "
  "business. It refuses to run for a business that still has unposted receipts that day, which is why step 4 matters.")
code("""
Schedule::command('efd:z-report')->dailyAt('23:55')->timezone('Africa/Dar_es_Salaam');

// or manually for one business / one day
$business->efd()->postZReport(Carbon::parse('2026-10-09'));
""")

step_heading(6, "Try it first from tinker (no UI needed)")
code("""
php artisan tinker
>>> $b = App\\Models\\Business::first();
>>> OnlineEfd\\Facades\\Efd::configure($b, [
...   'tin' => '150000091', 'cert_key' => '10TXXXXX73',
...   'certificate' => 'D:/certs/vfd.pfx',            // a file path works too
...   'certificate_password' => '******',
... ]);
>>> $b->efd()->register();
>>> $b->efd()->testConnection();                       // proves the TRA login works
>>> $b->efd()->issueReceipt(['items' => [['description' => 'Test', 'quantity' => 1, 'unit_price' => 1000]]]);
""")

# =========================================================== 4 reference
h1("4. Reference")
h2("Functions")
table([
    ["Call", "What it does"],
    ["Efd::configure($owner, array)", "Save tin, cert_key, certificate (file / path / bytes), certificate_password. Verifies the .pfx. Partial updates allowed."],
    ["Efd::status($owner)", "JSON-safe summary for the settings page. No secrets."],
    ["Efd::isReady($owner)", "true when configured, registered and able to issue receipts."],
    ["Efd::removeCertificate($owner)", "Delete the stored certificate."],
    ["$owner->efd()->register()", "Register with TRA; stores the credentials TRA returns."],
    ["$owner->efd()->testConnection()", "Check the stored TRA login works."],
    ["$owner->efd()->issueReceipt($json, $sale)", "Create and post a receipt now. Returns the receipt record."],
    ["$owner->efd()->issueReceiptLater($json, $sale)", "Reserve it now, post from the queue."],
    ["$owner->efd()->issueTestReceipt($json)", "Send a test receipt: real to TRA, flagged as a test, kept out of Z-reports."],
    ["Efd::forProfile($p)->retry($receipt)", "Re-send a failed/pending receipt."],
    ["$owner->efd()->postZReport(?Carbon)", "Post the day's Z-report (default: today)."],
], [80, 90], mono_first=True)

h2("Receipt JSON")
code("""
{
  "items": [                                   // required, at least one
    { "description": "Soda 500ml",             // required
      "quantity": 3,                           // required
      "unit_price": 1500,                      // required, VAT-INCLUSIVE
      "tax_code": 1,                           // optional, default 1
      "id": "SKU-1" }                          // optional, default = line number
  ],
  "payment_type": "CASH",                      // optional, default CASH
  "customer": { "name": "Jane", "phone": "255700000000", "id_type": 5, "id": "1234" },
  "issued_at": "2026-10-10 14:30:00"           // optional, default now
}
""")
table([
    ["Field", "Allowed values"],
    ["tax_code", "1 = 18% VAT (default) | 2 = special rate | 3 = zero rated | 4 = special relief | 5 = exempt"],
    ["payment_type", "CASH | CHEQUE | CCARD (credit card) | EMONEY (mobile money) | INVOICE (credit sale)"],
    ["customer.id_type", "1 TIN | 2 driving licence | 3 voter | 4 passport | 5 national ID | 6 none (default)"],
], [34, 136], mono_first=True)
p("Bad input throws <font face='Courier'>InvalidArgumentException</font> naming the field (e.g. "
  "<i>items.0.unit_price is required</i>). TRA, network and certificate problems throw "
  "<font face='Courier'>OnlineEfd\\Exceptions\\EfdException</font> with a message that is safe to show to users.")

h2("Efd::status() result")
code("""
{ "configured": true, "tin": "150000091", "cert_key": "10TXXXXX73",
  "has_certificate": true, "certificate_expires_at": "2027-03-01T00:00:00+00:00",
  "registered": true, "ready": true,
  "taxpayer": { "name": "ACME LTD", "vrn": "...", "regid": "TZ...", "tax_office": "..." } }
""")

h2("Receipt statuses and events")
bullets([
    "<b>pending</b>: reserved, not yet accepted. <b>posted</b>: accepted by TRA. <b>failed</b>: rejected or unreachable, retry it.",
    "Listen for <font face='Courier'>OnlineEfd\\Events\\ReceiptPosted</font> and <font face='Courier'>ReceiptFailed</font> to notify users or store the link.",
])

h2("Config (php artisan vendor:publish --tag=efd-config)")
table([
    ["Key", "Default / purpose"],
    ["timezone", "Africa/Dar_es_Salaam. Receipt time, Z-number and the daily counter reset use it."],
    ["api.base_url", "https://virtual.tra.go.tz/efdmsRctApi (env EFD_BASE_URL)"],
    ["api.verify_ssl", "true (env EFD_VERIFY_SSL). Fix the server's CA bundle rather than turning it off."],
    ["tax_rates", "Fallback VAT %. The rates TRA returns at registration take precedence."],
    ["queue.*", "connection, queue, tries (5) and backoff (30/120/600 s) for issueReceiptLater()."],
], [34, 136], mono_first=True)

# =========================================================== 5 salesmanager
h1("5. How salesmanager uses it")
p("salesmanager is multi-tenant and multi-business, with the subscription held by the account owner. The package "
  "is wired in like this (code in <font face='Courier'>app/Services/BusinessEfd.php</font> and "
  "<font face='Courier'>app/Http/Controllers/API/EfdController.php</font> of salesmanager):")
bullets([
    "<b>Paid add-on, also a package feature.</b> EFD is the package feature <font face='Courier'>efd</font> and an add-on the platform admin prices separately (same billing periods as plans). A business can use EFD when its plan includes the feature or its account has an active add-on. Unlike other features this fails closed: no plan, no EFD.",
    "<b>Shared or own details, switchable any time.</b> Each business is set to <i>off</i>, <i>shared</i> or <i>own</i>. <i>Shared</i> keeps one profile on the account owner, so the TIN, certificate and receipt counter are used by every business the owner ticks. <i>Own</i> keeps a profile on the business itself. Switching never deletes either profile.",
    "<b>Why shared means one profile.</b> TRA numbers receipts per registration, so businesses on the same TIN and certificate must share one counter. They cannot each keep their own.",
    "<b>Who can change what.</b> Anyone with the general-settings permission manages a business's own details. Shared details and the sharing list are the account owner's only.",
    "<b>Receipts when an invoice is paid.</b> With the per-business switch on, an invoice that becomes fully paid is turned into a receipt after the sale is saved (a queued job, never part of the checkout request). VAT, discount and returns are folded into the line prices and transport becomes its own line, so the receipt equals what the customer paid to the cent. A failure never affects the sale: it is recorded and re-sent by <font face='Courier'>efd:retry</font>.",
    "<b>Number and QR on printed documents.</b> Once TRA has accepted a receipt, the invoice page, the POS slip and the invoice PDF show the receipt number, the verification code (<font face='Courier'>RCTVNUM</font> followed by the global counter) and a QR of <font face='Courier'>verification_url</font>. The POS waits a few seconds after a fully paid sale so they are on the slip.",
    "<b>Test area per business.</b> A simple receipt form posts through <font face='Courier'>issueTestReceipt()</font>; every attempt (accepted, rejected, or not sent) is kept in <font face='Courier'>efd_test_runs</font> with what was sent and TRA's reply.",
])
note("Test receipts are real requests to TRA and use real receipt numbers. We flag them and leave them out of our Z-report, but TRA still records them. Use small amounts, and ask TRA whether a separate test environment is available.")

# =========================================================== 6 rules
h1("6. Rules worth knowing")
bullets([
    "<b>Prices are VAT-inclusive.</b> The package splits them: net = price / 1.18, VAT = price - net. Lines always add up exactly.",
    "<b>One receipt per invoice.</b> Pass the invoice as the second argument and repeated calls return the same receipt.",
    "<b>Numbers never skip.</b> Each receipt gets the next global and daily counter inside a database lock; a failed receipt keeps its numbers for the retry.",
    "<b>Z-report is all-or-nothing for the day.</b> It is refused while any real receipt of that day is not accepted by TRA. Test receipts are ignored.",
    "<b>Changing TIN or certificate key</b> on a registered business clears its registration; register again.",
    "<b>Authorization is yours.</b> The package trusts the owner model you pass it. Resolve the business from the logged-in user.",
])

h2("What is stored")
table([
    ["Table", "Contents"],
    ["efd_profiles", "One per business: TIN, certificate key, encrypted .pfx + password, TRA login, taxpayer details, counters, cumulative gross, cached token (encrypted)."],
    ["efd_receipts", "One per receipt: numbers, amounts, VAT split, status, TRA reply, signed request/response XML, verification link, link to your invoice."],
    ["efd_z_reports", "One per business per day: totals, status, TRA reply."],
], [32, 138], mono_first=True)

h2("Under the hood (for debugging)")
table([
    ["Step", "TRA call"],
    ["Register", "POST /api/vfdRegReq with signed REGDATA (TIN + certificate key). Returns the TRA login and taxpayer details."],
    ["Token", "POST /vfdtoken (username, password). Cached until 30 s before it expires."],
    ["Receipt", "POST /api/efdmsRctInfo, signed RCT XML, bearer token, headers Cert-Serial, Routing-Key, Client."],
    ["Z-report", "POST /api/efdmszreport, signed ZREPORT XML."],
], [24, 146])
p("Signing is SHA1withRSA with the business's own <font face='Courier'>.pfx</font>, as TRA requires.")

# =========================================================== 6 troubleshooting
h1("7. Troubleshooting")
table([
    ["Message / symptom", "Fix"],
    ["Certificate error: could not open the .pfx", "Wrong password, or an old-format .pfx on OpenSSL 3. Re-export: openssl pkcs12 -legacy -in old.pfx -nodes -out tmp.pem, then openssl pkcs12 -export -in tmp.pem -out new.pfx."],
    ["TRA rejected the request (code N)", "The text after the code comes from TRA. Check TIN, certificate key, certificate expiry and the server clock."],
    ["Could not reach TRA", "Network or TRA outage. The receipt is saved as failed; efd:retry or issueReceiptLater() will send it."],
    ["cURL error 60 / SSL certificate problem", "Update the server's CA bundle (curl.cainfo in php.ini). Do not disable verify_ssl."],
    ["Cannot post Z-report: receipts not accepted", "Retry the failed receipts for that day, then post again."],
    ["Stored certificate suddenly unreadable", "APP_KEY was changed. Restore it, or upload the certificate again."],
], [58, 112])

h2("Known limitations")
bullets([
    "<b>Not yet verified against live TRA:</b> the counter rule (next number = registration GC + 1) and the verification-link format. Run one real registration and receipt with your test certificate before go-live.",
    "No discounts, voids, credit notes or corrections yet (sent as zero).",
    "TRA's response signature is not verified. One EFD profile per business.",
])

h2("Tests")
code("""
cd online_efd && composer install && vendor/bin/phpunit      # 20 tests, TRA is faked
""")

# =========================================================== changelog
h1("8. Changelog")
for ver, date, items in CHANGELOG:
    story.append(KeepTogether(
        [Paragraph(f"<b>{ver}</b> &nbsp;<font color='#64748B'>{date}</font>", H2)]
        + [Paragraph(i, BULLET, bulletText="\u2022") for i in items]))
p("<i>Maintaining this guide: edit docs/build_documentation.py, add a changelog entry, bump VERSION, "
  "run python docs/build_documentation.py.</i>")


# =========================================================== page chrome
W, H = A4


def cover(canvas, doc):
    canvas.saveState()
    canvas.setFillColor(NAVY)
    canvas.rect(0, H - 14 * mm, W, 14 * mm, stroke=0, fill=1)
    canvas.setFillColor(EMERALD)
    canvas.rect(0, H - 16.2 * mm, W, 2.2 * mm, stroke=0, fill=1)
    canvas.drawImage(str(LOGO), 20 * mm, H - 78 * mm, width=64 * mm, height=32 * mm, mask="auto")
    canvas.setFillColor(EMERALD)
    canvas.rect(20 * mm, H - 100 * mm, 22 * mm, 1.6 * mm, stroke=0, fill=1)
    canvas.setFillColor(NAVY)
    canvas.rect(0, 0, W, 16 * mm, stroke=0, fill=1)
    canvas.setFillColor(colors.white)
    canvas.setFont("Helvetica", 8.5)
    canvas.drawString(20 * mm, 6.2 * mm, "salesManager  |  Online EFD developer guide")
    canvas.drawRightString(W - 20 * mm, 6.2 * mm, f"v{VERSION}")
    canvas.restoreState()


def chrome(canvas, doc):
    canvas.saveState()
    canvas.drawImage(str(LOGO), 20 * mm, H - 16 * mm, width=22 * mm, height=11 * mm, mask="auto")
    canvas.setFont("Helvetica", 8)
    canvas.setFillColor(SLATE)
    canvas.drawRightString(W - 20 * mm, H - 11.5 * mm, "Online EFD  |  Developer Guide")
    canvas.setStrokeColor(EMERALD)
    canvas.setLineWidth(1.2)
    canvas.line(20 * mm, H - 17.5 * mm, W - 20 * mm, H - 17.5 * mm)
    canvas.setStrokeColor(BORDER)
    canvas.setLineWidth(0.5)
    canvas.line(20 * mm, 14 * mm, W - 20 * mm, 14 * mm)
    canvas.drawString(20 * mm, 9.5 * mm, f"v{VERSION}")
    canvas.drawRightString(W - 20 * mm, 9.5 * mm, f"Page {doc.page}")
    canvas.restoreState()


doc = SimpleDocTemplate(
    str(OUT), pagesize=A4, leftMargin=20 * mm, rightMargin=20 * mm, topMargin=24 * mm, bottomMargin=20 * mm,
    title=f"Online EFD Developer Guide v{VERSION}", author="salesManager",
)
doc.build(story, onFirstPage=cover, onLaterPages=chrome)
print(f"Wrote {OUT}")
