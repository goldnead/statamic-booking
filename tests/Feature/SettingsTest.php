<?php

namespace Goldnead\StatamicBooking\Tests\Feature;

use Goldnead\BrandContext\Facades\BrandSettings;
use Goldnead\BrandContext\Settings\SettingsRegistry;
use Goldnead\StatamicBooking\Models\Booking;
use Goldnead\StatamicBooking\Support\Settings;
use Goldnead\StatamicBooking\Support\SignatureVerifier;
use Goldnead\StatamicBooking\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Test;

/**
 * Die Einstellungen dieses Addons an der geteilten Schicht.
 *
 * Geprüft wird nicht, dass die Schicht funktioniert — das gehört in deren
 * eigene Suite — sondern dass dieses Addon richtig daran hängt, und dass ein
 * gespeicherter Wert bis zum Leser durchkommt. Die drei Leser sind hier der
 * Aufräumbefehl, die Anfragebremse und die Signaturprüfung.
 */
class SettingsTest extends TestCase
{
    protected function booking(array $overrides = []): Booking
    {
        return Booking::create(array_merge([
            'endpoint' => 'beratung',
            'external_id' => 'cal-'.uniqid(),
            'status' => Booking::STATUS_BOOKED,
            'scheduled_at' => now()->addDay(),
            'name' => 'Maria Beispiel',
            'email' => 'maria@example.com',
        ], $overrides));
    }

    #[Test]
    public function it_registers_itself_with_the_shared_settings_layer(): void
    {
        $registry = app(SettingsRegistry::class);

        $this->assertTrue($registry->has('booking'), 'boot() hat die Einstellungen nicht angemeldet.');
        $this->assertSame(Settings::class, $registry->provider('booking'));
        // Nicht `booking`: die Config-Datei heißt anders als der Namensraum,
        // und ein falscher Pfad schriebe Werte in fremde Config.
        $this->assertSame('statamic-booking', $registry->configPath('booking'));
        $this->assertSame('manage booking settings', $registry->permission('booking'));
    }

    #[Test]
    public function a_saved_retention_decides_what_the_prune_command_deletes(): void
    {
        $this->booking(['scheduled_at' => now()->subDays(40), 'external_id' => 'alt']);
        $this->booking(['scheduled_at' => now()->subDays(10), 'external_id' => 'neu']);

        // Vorgabe ist 730 Tage: ohne die Einstellung ueberlebt beides.
        Artisan::call('statamic:booking:prune');
        $this->assertSame(2, Booking::count());

        BrandSettings::for('booking')->save(['keep_days' => 30]);

        Artisan::call('statamic:booking:prune');

        // Das ist die Grenze, die zaehlt: eine Zeile mit Name und Adresse ist
        // weg, weil jemand eine Frist im Control Panel gesetzt hat.
        $this->assertSame(['neu'], Booking::pluck('external_id')->all());
    }

    #[Test]
    public function a_saved_rate_limit_reaches_the_limiter(): void
    {
        $limit = (RateLimiter::limiter('statamic-booking'))(Request::create('/'));

        $this->assertSame(60, $limit->maxAttempts);

        BrandSettings::for('booking')->save(['rate_limit' => 10]);

        // Der Aufruf liegt in der Closure, die je Anfrage laeuft — deshalb
        // darf dieser Schluessel ueberhaupt auf die Seite.
        $limit = (RateLimiter::limiter('statamic-booking'))(Request::create('/'));

        $this->assertSame(10, $limit->maxAttempts);
    }

    #[Test]
    public function a_saved_tolerance_refuses_an_old_delivery(): void
    {
        // Der Header selbst bleibt in der Config: er ist Teil des
        // Protokollvertrags mit der Gegenstelle. Ohne ihn prueft die Toleranz
        // nichts, hier wird er deshalb gesetzt.
        config(['statamic-booking.signature.timestamp_header' => 'X-Cal-Timestamp']);

        $body = '{"ok":true}';
        $stale = now()->subSeconds(120)->getTimestamp();

        $request = Request::create('/', 'POST', [], [], [], [
            'HTTP_X_CAL_SIGNATURE_256' => hash_hmac('sha256', $stale.'.'.$body, 'geheim'),
            'HTTP_X_CAL_TIMESTAMP' => (string) $stale,
        ], $body);

        // Vorgabe 300 Sekunden: 120 Sekunden alt geht durch.
        $this->assertTrue(app(SignatureVerifier::class)->verify($request, 'geheim'));

        BrandSettings::for('booking')->save(['signature.tolerance_seconds' => 60]);

        $this->assertSame(
            'timestamp outside tolerance',
            app(SignatureVerifier::class)->verify($request, 'geheim')
        );
    }

    #[Test]
    public function no_secret_and_no_protocol_key_is_offered(): void
    {
        $offered = array_keys(app(SettingsRegistry::class)->fields('booking'));

        // `endpoints` traegt je Eintrag ein Geheimnis; eine Datenbankzeile
        // damit landet in jedem Backup.
        $this->assertNotContains('endpoints', $offered);

        foreach (['signature.header', 'signature.algorithm', 'signature.timestamp_header'] as $key) {
            $this->assertNotContains($key, $offered);
        }
    }
}
