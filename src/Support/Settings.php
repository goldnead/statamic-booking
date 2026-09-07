<?php

namespace Goldnead\StatamicBooking\Support;

use Goldnead\BrandContext\Contracts\ProvidesSettings;

/**
 * Die Betriebswerte, die ein Betreiber im Control Panel ändern darf.
 *
 * Nur die Feldliste. Seite, Formular, Validierung, Speicher und Rechteprüfung
 * kommen aus `goldnead/statamic-brand-context` — siehe {@see ProvidesSettings}.
 *
 * **Was nicht hier steht, und warum.**
 *
 * - `endpoints`. Eine verschachtelte Abbildung, und jeder Eintrag trägt ein
 *   `secret`. Ein Geheimnis in einer Datenbankzeile ist ein Geheimnis in jedem
 *   Backup und jedem Export; es bleibt in der Umgebung.
 * - `signature.header`, `signature.algorithm`, `signature.timestamp_header`.
 *   Der Protokollvertrag mit Cal.com. Wer einen davon verstellt, ohne dass die
 *   Gegenseite mitzieht, schaltet den Endpunkt ab — und zwar still, denn eine
 *   abgelehnte Signatur sieht aus wie ein Angriff, nicht wie ein Tippfehler.
 *
 * **`rate_limit` steht hier trotz einer Fundstelle im ServiceProvider.** Der
 * Aufruf liegt *innerhalb* der Closure, die `RateLimiter::for()` bekommt, und
 * die läuft je Anfrage — nicht beim Booten. Nachgesehen am 07.09.2026.
 */
class Settings implements ProvidesSettings
{
    /**
     * Bleibt für immer stehen: der Wert steht in `brand_settings.namespace` in
     * jeder Zeile, ein neuer Name verwaist jede gespeicherte Änderung.
     */
    public static function settingsNamespace(): string
    {
        return 'booking';
    }

    /**
     * Nicht identisch mit dem Namensraum. Die Config-Datei heißt
     * `statamic-booking.php`, jedes `config('statamic-booking.…')` im Code
     * liest von dort.
     */
    public static function settingsConfigPath(): string
    {
        return 'statamic-booking';
    }

    public static function settingsPermission(): string
    {
        return 'manage booking settings';
    }

    /**
     * @return array<int, array{title: string, description: string, fields: array<int, array<string, mixed>>}>
     */
    public static function settingsGroups(): array
    {
        return [
            [
                'title' => __('statamic-booking::settings.groups.endpoint.title'),
                'description' => __('statamic-booking::settings.groups.endpoint.description'),
                'fields' => [
                    static::field('rate_limit', 'integer', ['min' => 1]),
                    // Leer heißt "keine Zeitprüfung" und ist ein echter
                    // Zustand — richtig für eine Gegenstelle, die keinen
                    // Zeitstempel schickt.
                    static::field('signature.tolerance_seconds', 'integer', ['min' => 1, 'nullable' => true]),
                ],
            ],
            [
                'title' => __('statamic-booking::settings.groups.retention.title'),
                'description' => __('statamic-booking::settings.groups.retention.description'),
                'fields' => [
                    static::field('keep_days', 'integer', ['min' => 1, 'nullable' => true]),
                ],
            ],
        ];
    }

    /**
     * Ein Feld, mit Beschriftung und Beschreibung aus den Sprachdateien.
     *
     * Der Übersetzungsschlüssel ist der Config-Pfad mit flachgelegten Punkten:
     * ein Punkt im Sprachschlüssel ist für den Übersetzer ein Pfadtrenner.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected static function field(string $key, string $type, array $extra = []): array
    {
        $handle = str_replace('.', '_', $key);

        return array_merge([
            'key' => $key,
            'type' => $type,
            'label' => __("statamic-booking::settings.fields.{$handle}.label"),
            'description' => __("statamic-booking::settings.fields.{$handle}.description"),
            'nullable' => false,
        ], $extra);
    }
}
