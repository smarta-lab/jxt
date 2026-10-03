# Benutzung

[English](USAGE.md)

Alle Befehle werden im Projektordner einer Erweiterung ausgeführt. Meist ist das
der Ordner mit dem Manifest (`mod_*.xml` oder `templateDetails.xml`). Das
Manifest wird automatisch gefunden: Es ist die einzige XML-Datei in diesem
Ordner mit dem Wurzelelement `<extension>`.

Liegt die Erweiterung in einem Unterordner des Projekts (z. B. `extension/`,
während `composer.json`, `tests/` und `docs/` im Projektordner liegen) oder
enthält der Projektordner mehr als ein Manifest, wird das Manifest in der
Projektkonfiguration angegeben:

```bash
vendor/bin/jxt config set manifest extension/mod_example.xml
```

Wie beim Joomla-Installer gelten alle Pfade im Manifest relativ zum Ordner des
Manifests. `.jxt.json` und `dist/` bleiben im Projektordner.

Die Ausgabe von jxt ist englisch; die Beispiele unten zeigen sie unverändert.

## jxt sync – auf den Testserver übertragen

```bash
sudo vendor/bin/jxt sync            # übertragen
sudo vendor/bin/jxt sync --dry-run  # nur anzeigen, nichts ändern
```

`jxt sync` gleicht das Projekt mit dem Testserver ab und listet die Änderungen
auf:

```
mod_example → /var/www/html
  - media/mod_example/css/old.css
  + modules/mod_example/tmpl/new.php
  ~ modules/mod_example/src/Helper.php

 [OK] 2 copied, 1 deleted, 35 unchanged.
```

`+` neue Datei, `~` geänderte Datei, `-` gelöschte Datei oder gelöschter Ordner
(mit `/` am Ende).

### Was kopiert wird

Genau das, was im Manifest steht, mit denselben Zielen wie beim
Joomla-Installer:

| Abschnitt im Manifest | Ziel auf dem Server (Modul, Site) |
|---|---|
| das Manifest selbst, `<files>`, `<scriptfile>` | `modules/<element>/` |
| `<languages>` | `language/<tag>/` |
| `<media destination="…">` | `media/<destination>/` |

Administrator-Module landen unter `administrator/modules/` und
`administrator/language/`, Templates unter `templates/<name>/`. Dateien werden
nach Größe und Inhalt verglichen, unveränderte Dateien werden nicht kopiert.
Namen, die mit `.git` beginnen (z. B. `.gitkeep`), werden übersprungen.

### Was gelöscht wird

Das auf dem Server installierte Manifest beschreibt den zuletzt übertragenen
Stand.

- In den Ordnern, die der Erweiterung gehören (`modules/<element>/`,
  `media/<destination>/`), wird alles gelöscht, was nicht zum neuen Manifest
  gehört, auch ganze Ordner, die aus dem Manifest entfernt wurden.
- In gemeinsam genutzten Ordnern wie `language/de-DE/` werden nur Dateien
  gelöscht, die im alten Manifest standen und im neuen fehlen. Dateien anderer
  Erweiterungen werden nie angefasst.

Vor dem ersten Lauf auf einem bestehenden Server lohnt sich ein Blick auf
`--dry-run`.

### Erste Installation

`jxt sync` aktualisiert nur eine bereits installierte Erweiterung. Fehlt das
installierte Manifest, bricht es mit einer Fehlermeldung ab. Die Erweiterung
einmal im Joomla-Backend mit der ZIP-Datei aus `jxt build` installieren, danach
alle weiteren Änderungen mit `jxt sync` übertragen.

### Rechte und sudo

Die Dateien auf dem Testserver gehören meist dem Webserver-Benutzer (z. B.
`www-data`). Zwei Varianten funktionieren:

- **Mit sudo (Standard):** `owner` und `group` konfigurieren; `jxt sync` setzt
  sie sowie die Rechte `dir_mode`/`file_mode` (Standard 0755/0644).
- **Ohne sudo:** Der eigene Benutzer ist Mitglied der Webserver-Gruppe, und die
  Rechte erlauben der Gruppe Schreibzugriff, z. B. `dir_mode` 0775 und
  `file_mode` 0664. `owner` und `group` bleiben leer.

Die Rechte werden für die Dateien und Ordner der Erweiterung immer gesetzt,
Eigentümer und Gruppe nur als root. Sind `owner` oder `group` konfiguriert und
läuft `jxt sync` ohne sudo, gibt es eine Warnung; scheitert ohne sudo eine
Dateioperation, folgt der Hinweis, `jxt sync` mit sudo auszuführen.

### Warnungen und Hinweise

Kopieren ist nicht dasselbe wie eine Installation über den Joomla-Installer.
`jxt sync` meldet daher:

- **Geänderte Version:** Der Erweiterungsmanager zeigt die alte Version, bis sie
  unter „System → Verwalten → Erweiterungen“ mit „Cache aktualisieren“
  aufgefrischt wird.
- **Geänderter Namespace:** `administrator/cache/autoload_psr4.php` wird
  gelöscht, Joomla erzeugt die Datei neu.
- **Geänderte Installer-Abschnitte** (`<scriptfile>`, `<install>`,
  `<uninstall>`, `<update>`, `<updateservers>`) oder **neue/geänderte
  Installationsskripte und SQL-Dateien:** Diese wertet nur der Joomla-Installer
  aus. Stattdessen die ZIP-Datei aus `jxt build` im Backend installieren.
- **Dateien, die nicht im Manifest stehen:** Dateien in den Quellordnern von
  `<media>`, `<languages>` (und `<files folder="…">`), die das Manifest nicht
  aufführt, z. B. ein neuer Ordner `media/svg/`. Sie würden auf dem Server und
  im ZIP fehlen.

Standardwerte neuer Modulparameter werden nicht in bestehende Modulinstanzen
geschrieben. Das gilt auch bei einem echten Upgrade, deshalb Parameter immer mit
Standardwert lesen: `$params->get('x', default)`.

## jxt build – installierbare ZIP-Datei erstellen

```bash
vendor/bin/jxt build
```

Erstellt `dist/<element>-<version>.zip` (Templates:
`dist/tpl_<name>-<version>.zip`) mit dem Manifest und allen darin genannten
Dateien, jeweils mit ihrem Pfad relativ zum Manifest. Eine vorhandene Datei
gleichen Namens wird ersetzt. Wie `jxt sync` warnt `jxt build` vor Dateien, die
nicht im Manifest stehen.

Das ZIP wird aus der Arbeitskopie erstellt, also einschließlich nicht
committeter Änderungen.

## jxt config – Konfiguration anzeigen und ändern

```bash
vendor/bin/jxt config                               # Werte und Herkunft zeigen
vendor/bin/jxt config set <key> <value> [--global]  # Wert setzen
vendor/bin/jxt config unset <key> [--global]        # Wert entfernen
```

Ohne `--global` ändern `set` und `unset` die Projektdatei `.jxt.json`, mit
`--global` die globale Datei `~/.config/jxt/config.json`. `set <key> null`
speichert ein ausdrückliches `null`, z. B. um einen globalen `owner` für ein
Projekt abzuschalten. `jxt config` ohne sudo aufrufen, sonst gehörte die Datei
root; unter sudo verweigert es das Schreiben.

```
Key         Value         Origin
joomla_root /var/www/html global
owner       www-data      global
group       www-data      global
manifest    –             not set
dist_dir    dist          default
dir_mode    0775          project
file_mode   0644          default
```

Die Herkunft (`Origin`) ist die Projektdatei (`project`), die globale Datei
(`global`), der Standardwert (`default`) oder nicht gesetzt (`not set`).

## Konfiguration

Zwei JSON-Dateien werden zusammengeführt; Werte der Projektdatei überschreiben
die globalen.

| Datei | Zweck |
|---|---|
| `~/.config/jxt/config.json` | Globale Einstellungen, z. B. der Testserver |
| `.jxt.json` im Projektordner | Projektbezogene Abweichungen (optional) |

Unter sudo wird die globale Datei aus dem Home-Verzeichnis des Benutzers
gelesen, der sudo aufgerufen hat (`SUDO_USER`), nicht aus `/root`.

| Schlüssel | Bedeutung | Standard |
|---|---|---|
| `joomla_root` | Absoluter Pfad des Joomla-Testservers; muss `configuration.php` enthalten | – (für `jxt sync` erforderlich) |
| `owner` | Eigentümer der übertragenen Dateien, nur als root wirksam | – |
| `group` | Gruppe der übertragenen Dateien, nur als root wirksam | – |
| `manifest` | Manifest-Datei relativ zum Projektordner; nur in `.jxt.json` | – (wird im Projektordner gesucht) |
| `dist_dir` | Zielordner von `jxt build`, relativ zum Projekt oder absolut | `dist` |
| `dir_mode` | Rechte für Ordner | `0755` |
| `file_mode` | Rechte für Dateien | `0644` |

Alle Werte sind Texte oder `null`. Rechte werden vierstellig gespeichert (`775`
wird zu `0775`). Unbekannte Schlüssel werden abgewiesen, damit Tippfehler
auffallen.

Beispiel `~/.config/jxt/config.json`:

```json
{
    "joomla_root": "/var/www/html",
    "owner": "www-data",
    "group": "www-data"
}
```
