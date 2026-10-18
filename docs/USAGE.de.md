# Benutzung

[English](USAGE.md)

Alle Befehle werden im Projektordner einer Erweiterung ausgeführt. Meist ist das
der Ordner mit dem Manifest (`mod_*.xml`, `templateDetails.xml` oder die
XML-Datei des Plugins, z. B. `example.xml`). Das Manifest wird automatisch
gefunden: Es ist die einzige XML-Datei in diesem Ordner mit dem Wurzelelement
`<extension>`.

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

## jxt install – auf dem Testserver installieren

```bash
vendor/bin/jxt install          # installieren
vendor/bin/jxt install --clean  # installieren und Altlasten löschen
```

`jxt install` erstellt die ZIP-Datei wie `jxt build` und installiert sie mit dem
Joomla-Installer, ohne dass das Backend im Browser aufgerufen werden muss:

1. Die ZIP-Datei wird mit den Rechten 0644 nach `/tmp` kopiert, damit der
   Webserver-Benutzer sie lesen kann, ohne Zugriff auf das Projekt zu haben.
2. Die Joomla-CLI installiert sie als Webserver-Benutzer:

   ```bash
   sudo -n -u www-data php <joomla_root>/cli/joomla.php extension:install --path=…
   ```

3. Die Kopie in `/tmp` wird gelöscht.

```
mod_example-1.1.0.zip → /var/www/html
Install Extension
=================

 [OK] Extension installed successfully.

 [OK] mod_example 1.1.0 installed.
```

Das ist eine echte Installation bzw. ein echtes Update: Installationsskripte und
SQL-Dateien laufen, Update-Server werden eingetragen, die Version im
Erweiterungsmanager und der Namespace-Cache stimmen. Dateien, die im alten
Manifest standen und im neuen fehlen, löscht Joomla selbst; andere Altlasten
bleiben liegen (siehe [Altlasten](#altlasten)).

Für ein Update muss das Manifest `method="upgrade"` haben, sonst lehnt Joomla
die Installation über eine vorhandene Erweiterung ab. Schlägt die Installation
fehl und fehlt das Attribut, weist `jxt install` darauf hin.

### Git-Tag installieren

```bash
vendor/bin/jxt install --tag v1.0.0
```

Mit `--tag` erstellt `jxt install` die ZIP-Datei aus den committeten Dateien
eines Git-Tags wie `jxt build --tag` (siehe
[jxt build](#jxt-build--installierbare-zip-datei-erstellen)) und installiert
sie. So lässt sich genau der veröffentlichte Stand testen, z. B. das Update vom
letzten Release auf die Arbeitskopie. `--tag` und `--zip` lassen sich nicht
kombinieren (Exit-Code 2).

### Vorhandene ZIP-Datei installieren

```bash
vendor/bin/jxt install --zip dist/mod_example-1.0.0.zip
```

Mit `--zip` installiert `jxt install` eine vorhandene ZIP-Datei, statt eine neue
zu erstellen, z. B. eine ältere Version, um das Update von einem früheren
Release zu testen oder zu ihm zurückzukehren. Relative Pfade beziehen sich auf
das Projektverzeichnis. Das Manifest des Projekts wird nicht gebraucht; Name
der Erweiterung, Version in der Erfolgsmeldung und der Hinweis auf
`method="upgrade"` stammen aus dem Manifest im Hauptverzeichnis der ZIP-Datei.
Die Datei muss die Endung `.zip` haben, auf die sich der Joomla-Installer
verlässt.

Joomla prüft die Version nicht: Eine ältere ZIP-Datei über eine neuere
Installation zu installieren ist ein Downgrade, SQL-Updates der neueren Version
werden dabei nicht zurückgenommen.

### Altlasten

Aus Ordnern, die das Manifest als Ganzes aufführt (`<folder>src</folder>`),
entfernt der Joomla-Installer keine Dateien, z. B. eine aus `src/` gelöschte
Klasse, ebenso wenig Dateien aus früheren Übertragungen mit anderen Werkzeugen.
Nach einer erfolgreichen Installation vergleicht `jxt install` deshalb die
Ordner, die der Erweiterung gehören (`modules/<element>/`,
`media/<destination>/`, auch die des zuvor installierten Manifests), mit dem
Inhalt der eben installierten ZIP-Datei und listet alles andere auf; ein übrig
gebliebener Ordner erscheint einmal mit abschließendem `/`:

```
 [WARNING] Not removed by the Joomla installer:
           modules/mod_example/helper/
           modules/mod_example/tmpl/old.php

 ! [NOTE] They no longer belong to the extension. jxt install --clean removes
 !        them.
```

Ohne `--clean` wird nichts gelöscht, der Exit-Code bleibt 0. Maßstab ist die
ZIP-Datei, nicht die Arbeitskopie, daher stimmt der Vergleich auch mit `--tag`
und `--zip`. Beim Wechsel zwischen einem Release und der Arbeitskopie listet das
nächste `jxt install` auf, was der andere Stand hinterlassen hat.

Mit `--clean` löscht `jxt install` dieselben Einträge nach der Installation, wie
`jxt sync` seine Löschungen: über die
[jxt-Bridge](#jxt-bridge--sync-als-webserver-benutzer), wenn sie auf der Seite
installiert ist, sonst mit den eigenen Rechten. Scheitert das Löschen, ist der
Exit-Code 1.

```
Removing leftovers of the Joomla installer via jxt bridge:
  - modules/mod_example/helper/
  - modules/mod_example/tmpl/old.php

 [OK] mod_example 1.0.0 installed, 2 leftovers removed.
```

Bei Templates ist der Template-Ordner zugleich der Ort für im Backend angelegte
Overrides (`html/`) und für `user.css`/`user.js`. Sie stehen nicht in der
ZIP-Datei und gelten daher als Altlasten, die `--clean` löscht, wie es auch
`jxt sync` tut; vorher die Liste eines einfachen `jxt install` prüfen.

### Einrichtung: sudo ohne Passwort für den Joomla-Installer

Damit die Dateien dem Webserver-Benutzer gehören, läuft die Joomla-CLI als
dieser Benutzer (`web_user`, Standard `www-data`). `sudo -n` fragt nie nach
einem Passwort; nötig ist einmalig eine sudoers-Regel:

```bash
sudo visudo -f /etc/sudoers.d/jxt
```

```
user ALL=(www-data) NOPASSWD: /usr/bin/php8.5 /var/www/html/cli/joomla.php extension\:install *
```

Benutzername (hier `user`), PHP-Binary und Pfad müssen genau zu dem Aufruf
passen; fehlt die Regel, gibt `jxt install` die passende Zeile aus. Der
Doppelpunkt muss in sudoers maskiert werden.

Die Regel erlaubt nur, als `www-data` zu arbeiten, nicht als root. Über ein
Installationsskript lässt sich damit beliebiger Code als `www-data` ausführen;
das ist gewollt, denn genau dieser Code wird auf dem Testserver entwickelt. Der
Webserver erhält dabei keinen Zugriff auf das Home-Verzeichnis.

Läuft PHP auf dem Testserver ohnehin unter dem eigenen Benutzer (z. B. in einem
Container), schaltet `jxt config set web_user null` sudo ab.

## jxt sync – Dateien direkt übertragen

```bash
vendor/bin/jxt sync            # übertragen
vendor/bin/jxt sync --dry-run  # nur anzeigen, nichts ändern
```

`jxt sync` kopiert die Dateien ohne den Joomla-Installer. Das ist schneller und
löscht auch Altlasten. Der aufrufende Benutzer muss in den Zielordnern schreiben
dürfen (siehe [Rechte](#rechte)); gehören die Dateien dem Webserver-Benutzer,
einmal die [jxt-Bridge](#jxt-bridge--sync-als-webserver-benutzer) installieren,
dann schreibt `jxt sync` sie als dieser Benutzer über die Joomla-CLI.

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
`administrator/language/`, Templates unter `templates/<name>/`. Plugins landen
unter `plugins/<gruppe>/<element>/`, mit der Gruppe aus dem Attribut `group`
von `<extension>` und dem Element aus dem Attribut `plugin` in `<files>`
(z. B. `<folder plugin="example">services</folder>`); ihre Sprachdateien
landen wie beim Joomla-Installer unter `administrator/language/`. Dateien
werden nach Größe und Inhalt verglichen, unveränderte Dateien werden nicht
kopiert. Namen, die mit `.git` beginnen (z. B. `.gitkeep`), werden
übersprungen.

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
einmal mit `jxt install` installieren.

### Rechte

Ohne die jxt-Bridge arbeitet `jxt sync` mit den Rechten des aufrufenden
Benutzers. Die Rechte
`dir_mode`/`file_mode` (Standard 0755/0644) werden für die Dateien und Ordner
der Erweiterung und für neu angelegte Ordner gesetzt; bestehende gemeinsame
Ordner wie `language/de-DE/` bleiben unverändert. `chmod` darf nur der
Eigentümer einer Datei, Gruppenrechte reichen dafür nicht.

Scheitert eine Dateioperation, weist `jxt sync` auf `jxt bridge add` hin. Ist
die Bridge auf der Seite installiert, wendet sie denselben Plan als
Webserver-Benutzer an, mit denselben Rechten.

### Warnungen und Hinweise

Kopieren ist nicht dasselbe wie eine Installation über den Joomla-Installer.
`jxt sync` meldet daher:

- **Geänderte Version:** Der Erweiterungsmanager zeigt die alte Version, bis
  `jxt install` läuft oder sie unter „System → Verwalten → Erweiterungen“ mit
  „Cache aktualisieren“ aufgefrischt wird. Die jxt-Bridge frischt sie selbst
  auf, dann entfällt der Hinweis.
- **Geänderter Namespace:** `administrator/cache/autoload_psr4.php` wird
  gelöscht, Joomla erzeugt die Datei neu.
- **Geänderte Installer-Abschnitte** (`<scriptfile>`, `<install>`,
  `<uninstall>`, `<update>`, `<updateservers>`) oder **neue/geänderte
  Installationsskripte und SQL-Dateien:** Diese wertet nur der Joomla-Installer
  aus. Stattdessen `jxt install` ausführen.
- **Dateien, die nicht im Manifest stehen:** Dateien in den Quellordnern von
  `<media>`, `<languages>` (und `<files folder="…">`), die das Manifest nicht
  aufführt, z. B. ein neuer Ordner `media/svg/`. Sie würden auf dem Server und
  im ZIP fehlen. Diese Warnung geben auch `jxt install` und `jxt build` aus.

Standardwerte neuer Modulparameter werden nicht in bestehende Modulinstanzen
geschrieben. Das gilt auch bei einem echten Upgrade, deshalb Parameter immer mit
Standardwert lesen: `$params->get('x', default)`.

## jxt bridge – sync als Webserver-Benutzer

```bash
vendor/bin/jxt bridge add     # Bridge auf der Testseite installieren
vendor/bin/jxt bridge status  # Versionen und Joomla-CLI prüfen
vendor/bin/jxt bridge remove  # wieder deinstallieren
```

Die jxt-Bridge ist ein kleines Joomla-Konsolen-Plugin (`plg_console_jxt`), das
mit jxt geliefert wird. Es ergänzt die Joomla-CLI um den Befehl `jxt:apply`, der
den Plan von `jxt sync` als Webserver-Benutzer anwendet. Damit funktioniert
`jxt sync` auch auf Seiten, deren Dateien dem Webserver-Benutzer gehören, etwa
für schnelle Iterationen an CSS, JavaScript oder Layouts, ohne ein ZIP zu bauen
und zu installieren.

- **Ausdrückliche Installation:** `jxt bridge add` baut das Plugin aus dem
  jxt-Paket und installiert es mit dem Joomla-Installer; das Plugin aktiviert
  sich selbst. Danach prüft der Befehl, ob die Joomla-CLI die Befehle des
  Plugins ausführt.
- **So nutzt `jxt sync` sie:** Ist die Bridge auf der Seite installiert, legt
  `jxt sync` die geänderten Dateien und den Plan in einem Ordner unter `/tmp`
  ab (für den Webserver-Benutzer lesbar) und ruft `jxt:apply` über
  `sudo -n -u <web_user>` auf; die Ausgabe nennt `via jxt bridge`. Ohne Bridge
  kopiert `jxt sync` die Dateien wie bisher selbst.
- **Aktualisierung:** Ist die installierte Bridge älter als die mit dem eigenen
  jxt gelieferte, installiert `jxt sync` zuerst die mitgelieferte Version und
  meldet das. Eine neuere Bridge wird so genutzt, wie sie ist, damit Projekte
  mit unterschiedlichen jxt-Versionen auf einer Seite sie nicht abwechselnd neu
  installieren. Liest sie den Plan des eigenen jxt nicht mehr, bricht
  `jxt sync` mit einer Meldung ab; dann jxt im Projekt aktualisieren oder dessen
  Bridge mit `jxt bridge add` installieren.
- **Manifest-Cache:** Nach dem Anwenden frischt die Bridge den Manifest-Cache
  der Erweiterung auf, sodass der Erweiterungsmanager die neue Version zeigt.
- **Sicherheit:** Die Bridge reagiert nur auf Aufrufe über die Joomla-CLI und
  hat daher keinen Web-Endpunkt. Sie akzeptiert nur Ziele in den Ordnern von
  Erweiterungen (z. B. `modules/<element>/`, `media/<destination>/`,
  `language/<tag>/<datei>`) und lehnt alles andere ab, etwa
  `configuration.php`. Sie ist nur für Entwicklungsseiten gedacht; vor dem
  Kopieren einer Seite mit `jxt bridge remove` entfernen.

Die Bridge braucht neben der Regel für `jxt install` drei weitere
sudoers-Regeln (siehe
[Einrichtung](#einrichtung-sudo-ohne-passwort-für-den-joomla-installer)); fehlen
sie, geben `jxt bridge` und `jxt sync` alle vier Zeilen aus:

```
user ALL=(www-data) NOPASSWD: /usr/bin/php8.5 /var/www/html/cli/joomla.php extension\:install *
user ALL=(www-data) NOPASSWD: /usr/bin/php8.5 /var/www/html/cli/joomla.php extension\:remove -n *
user ALL=(www-data) NOPASSWD: /usr/bin/php8.5 /var/www/html/cli/joomla.php jxt\:apply *
user ALL=(www-data) NOPASSWD: /usr/bin/php8.5 /var/www/html/cli/joomla.php jxt\:status
```

`extension:remove` nutzt `jxt bridge remove`, mit `-n`, weil die Joomla-CLI
sonst nachfragt; `jxt:apply` und `jxt:status` sind die Befehle der Bridge. Jeder
Befehl braucht eine eigene Regel, da sudo-rs (das `sudo` neuerer
Ubuntu-Versionen) `*` nur als ganzes Argument akzeptiert, nicht innerhalb eines
Arguments wie `jxt\:*`.

## jxt build – installierbare ZIP-Datei erstellen

```bash
vendor/bin/jxt build               # aus der Arbeitskopie
vendor/bin/jxt build --tag v1.0.0  # aus einem Git-Tag
```

Erstellt `dist/<element>-<version>.zip` (Templates:
`dist/tpl_<name>-<version>.zip`, Plugins:
`dist/plg_<gruppe>_<element>-<version>.zip`) mit dem Manifest und allen darin
genannten Dateien, jeweils mit ihrem Pfad relativ zum Manifest. Eine vorhandene
Datei gleichen Namens wird ersetzt. Wie `jxt sync` warnt `jxt build` vor
Dateien, die nicht im Manifest stehen.

Das ZIP wird aus der Arbeitskopie erstellt, also einschließlich nicht
committeter Änderungen. Mit `--tag` entsteht es stattdessen aus den committeten
Dateien eines Git-Tags, z. B. für ein Release-ZIP: jxt exportiert den
Projektordner des Tags mit `git archive` in einen temporären Ordner, daher
fehlen auch Dateien, die in `.gitattributes` mit `export-ignore` markiert sind.
Das Manifest und die Einstellung `manifest` stammen aus der `.jxt.json` des
Tags, der Zielordner aus der Arbeitskopie. Nennt der Tag nicht die
Manifest-Version (mit oder ohne Präfix `v`), warnt jxt, erstellt das ZIP aber
trotzdem.

## jxt check – vor einem Release prüfen

```bash
vendor/bin/jxt check            # Vor- oder Endprüfung, siehe unten
vendor/bin/jxt check --release  # Endprüfung vor dem Taggen eines Releases
vendor/bin/jxt check --strict   # Warnungen zählen als Fehler, z. B. in der CI
vendor/bin/jxt check --tag v1.2.0 --release  # Endprüfung eines Tags
vendor/bin/jxt check --strict --format=github  # Annotationen in GitHub Actions
```

Mechanische Prüfungen der Erweiterung, abgeleitet aus dem Manifest und aus
Dateien, die es im Projekt schon gibt (`.editorconfig`, `CHANGELOG.md`,
Git-Tags). Es muss nichts eingeschaltet werden: Prüfungen ohne Grundlage
entfallen mit einem Hinweis. Die inhaltliche Bewertung (Einträge im Changelog,
README) bleibt Handarbeit.

Ob die Manifest-Version schon veröffentlicht ist, bestimmt den Modus, den die
erste Zeile der Ausgabe nennt:

- **Vorprüfung:** Die Version hat einen Git-Tag (`v1.2.0` oder `1.2.0`, je
  nach den vorhandenen Tags), es wird also an der nächsten Version gearbeitet.
- **Endprüfung:** Die Version hat noch keinen Tag und soll veröffentlicht
  werden. Vor dem ersten Release ist das immer so.
- Ohne Git entscheidet `CHANGELOG.md`: Eine datierte Überschrift für die
  Version gilt als veröffentlicht.
- Mit `--tag` ist es immer die Endprüfung dieses Tags (siehe
  [Git-Tag prüfen](#git-tag-prüfen)).

Probleme, die nur für ein Release zählen (fehlende Überschrift in
`CHANGELOG.md`, Einträge unter `[Unreleased]`, geänderte Asset-Datei ohne neue
Version), sind in der Endprüfung Hinweise und werden mit `--release` zu Fehlern.
`--strict` betrifft also den Zustand des Codes, `--release` die Vorbereitung des
Releases: Ein CI-Lauf bei jedem Push nutzt `jxt check --strict`, ohne vor der
Vorbereitung des Releases zu scheitern, der Lauf auf einem Tag
`jxt check --tag <tag> --strict --release`. In der Vorprüfung hat `--release`
keine Wirkung, darauf weist die Ausgabe hin.

Exit-Codes: `0` ohne Fehler, `1` mit Fehlern (mit `--strict` auch mit
Warnungen), `2` bei falschem Aufruf, falscher Konfiguration oder fehlerhaftem
Manifest. Hinweise lassen die Prüfung nie scheitern.

| Prüfung | Meldung bei | Vor | End |
|---|---|---|---|
| `manifest/version-semver` | `<version>` fehlt oder ist keine [semantische Version](https://semver.org/lang/de/) | Fehler | Fehler |
| `manifest/tag-version` | mit `--tag`: der Tag nennt nicht die Manifest-Version (`v1.2.0` oder `1.2.0`) | – | Fehler |
| `manifest/listed-files` | eine im Manifest genannte Datei oder ein Ordner fehlt | Fehler | Fehler |
| `manifest/unlisted-files` | eine Datei in einem Quellordner steht nicht im Manifest | Warnung | Warnung |
| `manifest/stray-files` | eine Müll-Datei in einem gelisteten Ordner käme ins ZIP (`.DS_Store`, `*~`, `*.orig`, versteckte Dateien) | Warnung | Warnung |
| `editorconfig/missing` | es gibt keine `.editorconfig`; die Gruppe entfällt | Hinweis | Hinweis |
| `editorconfig/indent-style` | Einrückung entgegen `indent_style` | Fehler | Fehler |
| `editorconfig/end-of-line` | Zeilenenden entgegen `end_of_line` | Fehler | Fehler |
| `editorconfig/final-newline` | Dateiende entgegen `insert_final_newline` | Fehler | Fehler |
| `editorconfig/trailing-whitespace` | Leerzeichen am Zeilenende entgegen `trim_trailing_whitespace` | Fehler | Fehler |
| `editorconfig/max-line-length` | eine Zeile ist länger als `max_line_length` (in Markdown entfallen Codeblöcke, Tabellen, Überschriften und Zeilen mit Links) | Warnung | Warnung |
| `editorconfig/charset` | ungültiges UTF-8 oder ein Byte Order Mark entgegen `charset` | Fehler | Fehler |
| `changelog/format` | `CHANGELOG.md` fehlt oder folgt nicht [Keep a Changelog](https://keepachangelog.com/de/); die Gruppe entfällt | Hinweis | Hinweis |
| `changelog/last-release` | die Manifest-Version ist nicht das letzte Release im Changelog | Warnung | – |
| `changelog/unreleased` | Vorprüfung: Anzahl der Einträge unter `[Unreleased]`; Endprüfung: `[Unreleased]` ist nicht leer | Hinweis | Hinweis, mit `--release` Fehler |
| `changelog/release-heading` | es gibt keine Überschrift `## [X.Y.Z] - YYYY-MM-DD` für die Version | – | Hinweis, mit `--release` Fehler |
| `assets/json` | eine `joomla.asset.json` ist kein gültiges JSON | Fehler | Fehler |
| `assets/uri` | die URI eines Assets passt zu keiner Datei der Erweiterung (externe URIs und Medien anderer Erweiterungen entfallen); bei `user.css` und `user.js` von Templates, die Websitebetreiber selbst anlegen (wie in Cassiopeia), nur ein Hinweis | Warnung | Warnung |
| `assets/version-changed` | die Datei des Assets hat sich seit dem letzten Release-Tag geändert, seine `version` nicht | Hinweis | Hinweis, mit `--release` Fehler |
| `assets/version-coupled` | eine Version war beim letzten Release gleich der Erweiterungsversion, ist es jetzt aber nicht | – | Hinweis |
| `assets/baseline` | kein Git oder noch kein Release-Tag, Asset-Versionen werden nicht verglichen | Hinweis | Hinweis |
| `language/parse` | eine Sprachdatei lässt sich nicht lesen | Fehler | Fehler |
| `language/files` | eine Sprachdatei fehlt in einer Sprache | Fehler | Fehler |
| `language/keys` | ein Schlüssel fehlt in einer Sprache | Fehler | Fehler |
| `language/undefined` | eine in PHP, XML oder JS verwendete Sprachkonstante ist nicht definiert | Fehler | Fehler |
| `language/unused` | eine definierte Konstante steht nicht im Code (sie kann zur Laufzeit zusammengesetzt werden; Name der Erweiterung, Template-Positionen und Layouts werden nie gemeldet) | Hinweis | Hinweis |
| `composer/version-field` | `composer.json` hat ein `version`-Feld | Warnung | Warnung |

Die Whitespace-Prüfungen umfassen die versionierten und die nicht versionierten,
nicht ignorierten Dateien des Git-Repositorys, ohne Git die Dateien des
Manifests und die nicht versteckten Dateien im Projektordner. Binärdateien
entfallen. Dateien wie Fremdbibliotheken nimmt man in der `.editorconfig` mit
`unset` aus.

### Git-Tag prüfen

```bash
vendor/bin/jxt check --tag v1.2.0 --strict --release
```

Mit `--tag` prüft `jxt check` die committeten Dateien eines Git-Tags statt der
Arbeitskopie, exportiert mit `git archive` wie bei `jxt build --tag`. Das ist
immer die Endprüfung dieses Releases: Der Tag muss die Manifest-Version nennen
(`manifest/tag-version`), Asset-Versionen werden mit der höchsten getaggten
Version darunter verglichen. Die Konfiguration samt `check_exclude` und
`check_include` stammt aus der `.jxt.json` des Tags. Dateien, die in
`.gitattributes` als `export-ignore` markiert sind, gehören nicht zum Export,
die Whitespace-Prüfungen lassen sie daher aus.

Ein Prüflauf auf dem Tag selbst, z. B. in der CI, braucht `--tag`: Ohne die
Option ist die Version schon getaggt, und `jxt check` macht eine Vorprüfung.
Der Checkout in der CI braucht die früheren Tags für den Vergleich der
Asset-Versionen (in GitHub Actions `fetch-depth: 0`). Ein unbekannter Tag oder
ein Projekt ohne Git ist ein falscher Aufruf (Exit-Code 2).

### Annotationen in GitHub Actions

```bash
vendor/bin/jxt check --strict --format=github
```

Mit `--format=github` gibt `jxt check` jede Meldung als Workflow-Befehl von
GitHub Actions aus (`::error`, `::warning`, `::notice`), den GitHub als
Annotation an Datei und Zeile anzeigt, im Pull Request und in der
Zusammenfassung des Laufs. Der Titel einer Annotation ist der Name der
Prüfung. Meldungen ohne Bezug zu einer Datei und falsche Aufrufe erhalten eine
Annotation ohne Ort; eine Meldung über mehrere Zeilen zeigt auf die erste.
Pfade sind relativ zum Wurzelordner des Repositorys, auch wenn das
Projektverzeichnis ein Unterordner davon ist.

Die erste Zeile (Version und Modus) und die Zusammenfassung bleiben normale
Zeilen, die Exit-Codes sind dieselben wie bei der Textausgabe
(`--format=text`, Standard). Mit `--strict` lassen Warnungen den Lauf
scheitern, bleiben aber Warnungs-Annotationen.

### Prüfungen abschalten

```bash
vendor/bin/jxt check --exclude=editorconfig --include=editorconfig/charset
vendor/bin/jxt check --exclude=all --include=language  # nur eine Gruppe
vendor/bin/jxt config set check_exclude assets/uri,editorconfig/max-line-length
```

`--exclude` und `--include` nehmen `all`, eine Gruppe (`manifest`) oder eine
einzelne Prüfung (`manifest/stray-files`), mehrfach oder mit Komma getrennt. In
`.jxt.json` tun `check_exclude` und `check_include` dasselbe dauerhaft für das
Projekt.

Die Kommandozeile überlagert `.jxt.json`. Innerhalb einer Quelle gewinnt der
genauere Name (`all` < Gruppe < Prüfung), so lässt sich eine einzelne Prüfung
aus einer abgeschalteten Gruppe wieder einschalten; beim selben Namen gewinnt
das Einschließen:

| `.jxt.json` | Kommandozeile | Ergebnis |
|---|---|---|
| exclude `editorconfig/charset` | – | charset entfällt |
| exclude `editorconfig/charset` | `--include=editorconfig` oder `--include=all` | charset läuft |
| include `editorconfig/charset` | `--exclude=editorconfig` | charset entfällt |
| – | `--exclude=all --include=editorconfig/charset` | nur charset läuft |

Mit `check_exclude: ["all"]` in `.jxt.json` wird die include-Liste zur
vollständigen Auswahl; Prüfungen, die spätere jxt-Versionen ergänzen, laufen in
dem Projekt dann nicht. Unbekannte Namen werden abgewiesen.

## jxt config – Konfiguration anzeigen und ändern

```bash
vendor/bin/jxt config                               # Werte und Herkunft zeigen
vendor/bin/jxt config set <key> <value> [--global]  # Wert setzen
vendor/bin/jxt config set <key> <value>...          # Liste setzen, auch mit Komma
vendor/bin/jxt config unset <key> [--global]        # Wert entfernen
```

Ohne `--global` ändern `set` und `unset` die Projektdatei `.jxt.json`, mit
`--global` die globale Datei `~/.config/jxt/config.json`. `set <key> null`
speichert ein ausdrückliches `null`. Bei Listen nimmt `set` mehrere Werte, auch
mit Komma getrennt, und ersetzt die ganze Liste. `jxt config` ohne sudo
aufrufen, sonst gehörte die Datei root; unter sudo verweigert es das Schreiben.

```
Key           Value         Origin
joomla_root   /var/www/html global
web_user      www-data      default
manifest      –             not set
dist_dir      dist          default
dir_mode      0775          project
file_mode     0644          default
check_exclude assets/uri    project
check_include –             not set
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
| `joomla_root` | Absoluter Pfad des Joomla-Testservers; muss `configuration.php` enthalten | – (für `jxt install` und `jxt sync` erforderlich) |
| `web_user` | Benutzer, als der `jxt install` und die jxt-Bridge die Joomla-CLI per sudo ausführen; `null` für einen Aufruf ohne sudo | `www-data` |
| `manifest` | Manifest-Datei relativ zum Projektordner; nur in `.jxt.json` | – (wird im Projektordner gesucht) |
| `dist_dir` | Zielordner von `jxt build` und `jxt install`, relativ zum Projekt oder absolut | `dist` |
| `dir_mode` | Rechte für Ordner bei `jxt sync` | `0755` |
| `file_mode` | Rechte für Dateien bei `jxt sync` | `0644` |
| `check_exclude` | Liste der Prüfungen, Gruppen oder `all`, die `jxt check` auslässt; nur in `.jxt.json` | – |
| `check_include` | Liste der Prüfungen, Gruppen oder `all`, die `jxt check` trotz `check_exclude` ausführt; nur in `.jxt.json` | – |

Alle Werte sind Texte, Listen von Texten (`check_exclude`, `check_include`)
oder `null`. `null` setzt einen Schlüssel auf seinen
Standardwert zurück, nur bei `web_user` bedeutet es „ohne sudo“. Rechte werden
vierstellig gespeichert (`775` wird zu `0775`). Unbekannte Schlüssel werden
abgewiesen, damit Tippfehler auffallen.

Beispiel `~/.config/jxt/config.json`:

```json
{
    "joomla_root": "/var/www/html"
}
```

## jxt ohne vendor/bin aufrufen

Composer installiert `jxt` nach `vendor/bin/` des Projekts, das nicht im `PATH`
liegt; die Beispiele oben rufen daher `vendor/bin/jxt` auf. Wer nur `jxt`
tippen möchte, kann mit [direnv](https://direnv.net) `vendor/bin/` beim
Betreten des Projektordners in den `PATH` aufnehmen und beim Verlassen wieder
entfernen.

direnv einmalig installieren und in die Shell einbinden (Debian/Ubuntu, Bash;
für andere Shells siehe die Dokumentation von direnv), danach eine neue Shell
öffnen:

```bash
sudo apt install direnv
echo 'eval "$(direnv hook bash)"' >> ~/.bashrc
```

In jedem Projektordner eine `.envrc` anlegen und freigeben:

```bash
echo 'PATH_add vendor/bin' > .envrc
direnv allow
```

direnv lädt eine `.envrc` erst nach `direnv allow` und nach jeder Änderung
erneut erst nach einer Freigabe, ein geklontes Repository kann also nicht
ungefragt Code ausführen. `.envrc` ist eine persönliche Einstellung und gehört
nicht ins Repository, z. B. über die globale Git-Ignore-Datei
`~/.config/git/ignore`.
