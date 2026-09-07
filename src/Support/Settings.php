<?php

namespace Goldnead\ClientRooms\Support;

use Goldnead\BrandContext\Contracts\ProvidesSettings;
use Goldnead\BrandContext\Settings\SettingsRegistry;

/**
 * Die Einstellungen, die ein Betreiber im Control Panel ändern darf, und die
 * einzige Stelle, die weiß, welche das sind.
 *
 * **Nur die Feldliste steht hier.** Seite, Formular, Validierung, Speicher,
 * Rechteprüfung und die Markendimension kommen aus der gemeinsamen
 * Einstellungs-Schicht in `statamic-brand-context`; angemeldet wird diese
 * Klasse über {@see SettingsRegistry} im Service Provider. Kein eigener
 * Controller, keine eigene Vue-Seite, keine eigene Route, keine eigene
 * Tabelle.
 *
 * **Diese Klasse wird nur geladen, wenn `statamic-brand-context` da ist.**
 * Das Paket steht in `suggest`, nicht in `require` — dieses Addon läuft
 * einmarkig ohne es. Eine Klasse kann eine Schnittstelle aber nur
 * implementieren, wenn es sie gibt, deshalb nennt der Service Provider
 * `Settings::class` erst innerhalb einer `class_exists`-Prüfung. Solange der
 * Klassenname nur als Konstante dasteht, fasst PHP die Datei nicht an.
 *
 * **Was nicht hier steht, und warum.**
 *
 * - `container` und `disk`. Der Speicherort jedes Raumdokuments. Ein Wechsel
 *   muss die Dateien erst bewegen; ein Feld, das den Zeiger umlegt und die
 *   Dateien stehen lässt, macht aus jedem bestehenden Raum einen leeren.
 *   `php please clientrooms:install` legt die Container an, und das ist der
 *   Weg, auf dem sie sich ändern.
 * - `member_api`. Wird beim Registrieren der Routen gelesen
 *   (`routes/web.php:46`). Die Einstellungs-Schicht schreibt ihre Werte aus
 *   einem `app->booted()`-Rückruf auf die Konfiguration, also nachdem die
 *   Routen entstanden sind. Ein Schalter hier wäre erst nach dem nächsten
 *   Deploy wirksam und bis dahin eine Falschaussage auf dem Bildschirm.
 */
class Settings implements ProvidesSettings
{
    /**
     * Steht auf jeder Zeile in `brand_settings.namespace`. Ihn später zu
     * ändern verwaist jede Abweichung, die eine Installation gespeichert hat.
     */
    public static function settingsNamespace(): string
    {
        return 'clientrooms';
    }

    /**
     * Die Konfigurationswurzel, der ungesetzte Werte weiter folgen — das ist,
     * was jedes `config('statamic-clientrooms.…')` in diesem Addon liest.
     * Nicht derselbe String wie der Namensraum, weil die Konfigurationsdatei
     * das Paket-Präfix trägt und der Namensraum das Addon benennt.
     */
    public static function settingsConfigPath(): string
    {
        return 'statamic-clientrooms';
    }

    /**
     * Das Recht, das den Abschnitt dieses Addons bewacht.
     *
     * Neu, also frei wählbar; nach der Regel `manage <handle> settings` mit
     * dem Paketnamen ohne `statamic-`-Präfix. Es liegt bewusst neben
     * `view client rooms` statt darunter: wer Räume lesen darf, sieht die
     * Notizen eines Klienten, und wer die Aufbewahrung von Downloadlinks
     * ändern darf, muss keinen einzigen Raum sehen.
     */
    public static function settingsPermission(): string
    {
        return 'manage clientrooms settings';
    }

    /**
     * @return array<int, array{title: string, description: string, fields: array<int, array<string, mixed>>}>
     */
    public static function settingsGroups(): array
    {
        return [
            [
                'title' => __('statamic-clientrooms::settings.groups.files.title'),
                'description' => __('statamic-clientrooms::settings.groups.files.description'),
                'fields' => [
                    static::field('allowed_extensions', 'list'),
                    // Kein Deckel nach oben: PHPs eigenes `upload_max_filesize`
                    // hat ohnehin das letzte Wort, und ein zweites Limit, das
                    // niedriger raten würde, verwirrt nur.
                    static::field('member_upload_max_kb', 'integer', ['min' => 1]),
                    static::field('download_ttl_minutes', 'integer', ['min' => 1]),
                ],
            ],
            [
                'title' => __('statamic-clientrooms::settings.groups.tasks.title'),
                'description' => __('statamic-clientrooms::settings.groups.tasks.description'),
                'fields' => [
                    static::field('task_types', 'list'),
                ],
            ],
            [
                'title' => __('statamic-clientrooms::settings.groups.opening.title'),
                'description' => __('statamic-clientrooms::settings.groups.opening.description'),
                'fields' => [
                    static::field('open_on_product_types', 'list'),
                    static::field('open_on_products', 'list'),
                    // Leer ist ein echter Zustand: der Raum entsteht ohne
                    // Besitzer und wartet darauf, dass jemand ihn übernimmt.
                    static::field('default_owner', 'string', ['nullable' => true]),
                ],
            ],
            [
                'title' => __('statamic-clientrooms::settings.groups.timeline.title'),
                'description' => __('statamic-clientrooms::settings.groups.timeline.description'),
                'fields' => [
                    static::field('timeline_limit', 'integer', ['min' => 1]),
                ],
            ],
        ];
    }

    /**
     * Ein Feld, mit Beschriftung und Hilfetext aus den Sprachdateien.
     *
     * Der Übersetzungsschlüssel ist der Konfigurationspfad mit flachgelegten
     * Punkten: ein Punkt in einem Sprachschlüssel ist für den Übersetzer ein
     * Pfadtrenner, und `settings.fields.open_on.products.label` würde als
     * verschachtelte Felder gesucht, die es nicht gibt. In diesem Addon liegt
     * heute kein Schlüssel tiefer als eine Ebene; der Ersatz steht trotzdem
     * hier, damit der erste, der einen anlegt, nicht darüber stolpert.
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
            'label' => __("statamic-clientrooms::settings.fields.{$handle}.label"),
            'description' => __("statamic-clientrooms::settings.fields.{$handle}.description"),
            'nullable' => false,
        ], $extra);
    }
}
