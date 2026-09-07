<?php

namespace Goldnead\ClientRooms\Tests\Feature;

use Goldnead\ClientRooms\Facades\ClientRooms;
use Goldnead\ClientRooms\Support\Settings;
use Goldnead\ClientRooms\Tests\TestCase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;

/**
 * Die Feldliste, die dieses Addon der gemeinsamen Einstellungs-Seite gibt.
 *
 * Was hier **nicht** geprüft wird und wo es geprüft wird: Formular,
 * Validierung, Speicher und das Zurückschreiben auf `config()` gehören
 * `statamic-brand-context`, und das Paket ist hier ein `suggest`, das nicht in
 * `vendor` liegt (`composer update -W` dafür würde `inertiajs/inertia-laravel`
 * von v3 auf v2 zurückdrehen, siehe Nebenbefund). Diese Hälfte der Kette wird
 * im Playground belegt, wo beide Pakete nebeneinander installiert sind.
 *
 * Was hier geprüft wird, ist die Hälfte, die diesem Addon gehört: dass jeder
 * angebotene Schlüssel wirklich existiert, dass keiner davon beim Booten
 * gelesen wird, und dass der Leser dahinter einen geänderten Wert zur
 * Anfragezeit sieht.
 */
class SettingsTest extends TestCase
{
    /** @return list<string> */
    protected function angeboteneSchluessel(): array
    {
        $keys = [];

        foreach (Settings::settingsGroups() as $group) {
            foreach ($group['fields'] as $field) {
                $keys[] = $field['key'];
            }
        }

        return $keys;
    }

    #[Test]
    public function jeder_angebotene_schluessel_existiert_wirklich(): void
    {
        // Ein vertippter Schlüssel gibt ein Feld, das sich speichern lässt und
        // auf nichts zeigt. Auf dem Bildschirm ist das nicht zu sehen.
        $config = require __DIR__.'/../../config/statamic-clientrooms.php';

        foreach ($this->angeboteneSchluessel() as $key) {
            $this->assertTrue(
                data_get($config, $key, '__fehlt__') !== '__fehlt__',
                "Die Einstellungs-Seite bietet [{$key}] an, aber config/statamic-clientrooms.php kennt den Schlüssel nicht."
            );
        }
    }

    #[Test]
    public function kein_angebotener_schluessel_wird_beim_booten_gelesen(): void
    {
        // Die Einstellungs-Schicht schreibt ihre Werte aus einem
        // `app->booted()`-Rückruf auf die Konfiguration. Alles, was vorher
        // gelesen wird — Routen, Nav, `register()` — sieht noch den Wert aus
        // der Datei. Ein Schalter, der erst nach dem nächsten Deploy wirkt,
        // ist eine Falschaussage auf dem Bildschirm.
        //
        // Gemessen am Text, nicht an einer Liste im Kopf: `member_api` steht
        // deshalb nicht auf der Seite, und diese Behauptung fällt um, sobald
        // jemand einen weiteren Schlüssel in die Routen zieht.
        $routen = file_get_contents(__DIR__.'/../../routes/web.php')
            .file_get_contents(__DIR__.'/../../routes/cp.php');

        foreach ($this->angeboteneSchluessel() as $key) {
            $this->assertStringNotContainsString(
                "statamic-clientrooms.{$key}",
                $routen,
                "Die Einstellungs-Seite bietet [{$key}] an, aber der Schlüssel wird beim Registrieren der Routen gelesen."
            );
        }
    }

    #[Test]
    public function der_leser_der_dateiendungen_sieht_einen_geaenderten_wert(): void
    {
        $this->makeContainer();
        $room = ClientRooms::open('maria@example.com');

        // Vorher: `.txt` steht nicht in der Vorgabeliste, der Schreibweg
        // weist die Datei ab. Ohne diesen Teil bewiese die Annahme danach nur,
        // dass hier nie etwas geprüft wurde.
        try {
            ClientRooms::attach($room, UploadedFile::fake()->create('notiz.txt', 2, 'text/plain'), 'Notiz');
            $this->fail('Eine .txt hätte mit der Vorgabeliste abgewiesen werden müssen.');
        } catch (\InvalidArgumentException) {
            // erwartet
        }

        // Das ist genau der Schreibvorgang, mit dem die Einstellungs-Schicht
        // endet: sie legt den gespeicherten Wert auf die lebende
        // Konfiguration. Ab hier ist der Weg derselbe wie im Betrieb.
        config()->set('statamic-clientrooms.allowed_extensions', ['pdf', 'txt']);

        $datei = ClientRooms::attach($room, UploadedFile::fake()->create('notiz.txt', 2, 'text/plain'), 'Notiz');

        $this->assertNotNull($datei->id);
        $this->assertSame('Notiz', $datei->title);
    }
}
