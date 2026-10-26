# Benutzung

[English](USAGE.md)

- [jxt install – auf dem Testserver installieren](#jxt-install--auf-dem-testserver-installieren)
  - [Git-Tag installieren](#git-tag-installieren)
  - [Vorhandene ZIP-Datei installieren](#vorhandene-zip-datei-installieren)
  - [Altlasten](#altlasten)
  - [Einrichtung: sudo ohne Passwort für den Joomla-Installer](#einrichtung-sudo-ohne-passwort-für-den-joomla-installer)
- [jxt sync – Dateien direkt übertragen](#jxt-sync--dateien-direkt-übertragen)
  - [Was kopiert wird](#was-kopiert-wird)
  - [Was gelöscht wird](#was-gelöscht-wird)
  - [Erste Installation](#erste-installation)
  - [Rechte](#rechte)
  - [Warnungen und Hinweise](#warnungen-und-hinweise)
- [jxt bridge – sync als Webserver-Benutzer](#jxt-bridge--sync-als-webserver-benutzer)
- [Testseiten in Containern](#testseiten-in-containern)
  - [DDEV](#ddev)
  - [Offizielles Joomla-Image](#offizielles-joomla-image)
- [jxt build – installierbare ZIP-Datei erstellen](#jxt-build--installierbare-zip-datei-erstellen)
- [Build-Schritte](#build-schritte)
- [jxt check – vor einem Release prüfen](#jxt-check--vor-einem-release-prüfen)
  - [Git-Tag prüfen](#git-tag-prüfen)
  - [Annotationen in GitHub Actions](#annotationen-in-github-actions)
  - [Prüfungen abschalten](#prüfungen-abschalten)
- [jxt site – Testseiten verwalten](#jxt-site--testseiten-verwalten)
- [jxt config – Konfiguration anzeigen und ändern](#jxt-config--konfiguration-anzeigen-und-ändern)
- [Konfiguration](#konfiguration)
  - [Testseiten](#testseiten)
- [jxt ohne vendor/bin aufrufen](#jxt-ohne-vendorbin-aufrufen)

Alle Befehle werden im Projektordner einer Erweiterung ausgeführt. Meist ist das
der Ordner mit dem Manifest (`mod_*.xml`, `templateDetails.xml` oder die
XML-Datei eines Plugins oder einer Komponente, z. B. `example.xml`). Das
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

## jxt install – auf dem Testserver installieren

```bash
vendor/bin/jxt install          # installieren
vendor/bin/jxt install --clean  # installieren und Altlasten löschen
```

`jxt install` erstellt die ZIP-Datei wie `jxt build`, nach den
[Build-Schritten](#build-schritte), und installiert sie mit dem
Joomla-Installer, ohne dass das Backend im Browser aufgerufen werden muss:

1. Die ZIP-Datei wird mit den Rechten 0644 nach `/tmp` kopiert, damit der
   Webserver-Benutzer sie lesen kann, ohne Zugriff auf das Projekt zu haben.
2. Die Joomla-CLI installiert sie als Webserver-Benutzer (`web_user`):

   ```bash
   sudo -n -u www-data php <joomla_root>/cli/joomla.php extension:install --path=…
   ```

3. Die Kopie in `/tmp` wird gelöscht.

Bei einer Seite in einem Container landet die Kopie im Ordner `tmp/` der Seite,
und die Joomla-CLI läuft über `cli_command` (siehe
[Testseiten in Containern](#testseiten-in-containern)).

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

Die Joomla-CLI ist eine Konsolen-Anwendung, keine Web-Anwendung. Ein
Installationsskript, das Letztere erwartet, z. B. weil es `$app` als
`CMSApplication` deklariert, scheitert dort; eine solche Erweiterung lässt sich
nur im Backend installieren.

Installationsskripte geben oft HTML aus, das das Backend darstellt.
`jxt install` wandelt es in reinen Text um, umbrochen bei der Breite des
Terminals wie die Meldungen von jxt (höchstens 120 Zeichen, 80, wenn die
Breite unbekannt ist); die übrigen Zeilen der Joomla-CLI bleiben unverändert.

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
dieser Benutzer (`web_user`), der einmalig für die [Testseite](#testseiten)
gesetzt wird, nachdem man sie angelegt hat:

```bash
vendor/bin/jxt site add local --default
vendor/bin/jxt config set joomla_root /var/www/html
vendor/bin/jxt config set web_user www-data
```

`sudo -n` fragt nie nach einem Passwort; nötig ist einmalig eine sudoers-Regel:

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

Läuft PHP auf dem Testserver ohnehin unter dem eigenen Benutzer, bleibt
`web_user` ungesetzt; jxt führt die Joomla-CLI dann als der eigene Benutzer
ohne sudo aus. Darf man nicht in `joomla_root` schreiben, lehnt jxt das ab und
schlägt den Eigentümer der Seite als `web_user` vor. Für ein Projekt, das eine
andere Seite nutzt, siehe [Testseiten](#testseiten), für eine Seite in einem
Container [Testseiten in Containern](#testseiten-in-containern).

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

Vor dem Vergleich führt `jxt sync` die [Build-Schritte](#build-schritte) aus,
mit ihrem Ersatz für `jxt sync`; auch mit `--dry-run`, das nur die Testseite
unverändert lässt.

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
unter `plugins/<gruppe>/<element>/`, mit der Gruppe aus dem Attribut `group` von
`<extension>` und dem Element aus dem Attribut `plugin` in `<files>` (z. B.
`<folder plugin="example">services</folder>`); ihre Sprachdateien landen wie
beim Joomla-Installer unter `administrator/language/`. Komponenten (Element
`com_<name>`) haben drei Ordner: `<files>` landet unter
`components/<element>/`, `<files>` in `<administration>` zusammen mit dem
Manifest und `<scriptfile>` unter `administrator/components/<element>/` und
`<files>` in `<api>` unter `api/components/<element>/`; `<languages>` landet
unter `language/<tag>/`, `<languages>` in `<administration>` unter
`administrator/language/<tag>/`. Bibliotheken landen unter
`libraries/<libraryname>/` (der Name aus `<libraryname>` darf `/` enthalten,
z. B. `acme/example`), ihr Manifest unter
`administrator/manifests/libraries/<libraryname>.xml` und ihre Sprachdateien
unter `language/<tag>/`. Das Attribut `client`
eines `<language>`-Eintrags legt den Bereich für diese Datei fest. Sprachdateien
einer Sprache, die auf dem Server nicht installiert ist, werden wie beim
Joomla-Installer übersprungen und aufgelistet. Dateien werden nach Größe und
Inhalt verglichen, unveränderte Dateien werden nicht kopiert. Namen, die mit
`.git` beginnen (z. B. `.gitkeep`), werden übersprungen.

### Was gelöscht wird

Das auf dem Server installierte Manifest beschreibt den zuletzt übertragenen
Stand.

- In den Ordnern, die der Erweiterung gehören (`modules/<element>/`, die drei
  Ordner einer Komponente, `libraries/<libraryname>/`,
  `media/<destination>/`), wird alles gelöscht, was
  nicht zum neuen Manifest gehört, auch ganze Ordner, die aus dem Manifest
  entfernt wurden.
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
Benutzers. Die Dateien der Erweiterung erhalten die Rechte 0644, ihre Ordner
und neu angelegte Ordner 0755, wie sie auch der Joomla-Installer anlegt;
bestehende gemeinsame Ordner wie `language/de-DE/` bleiben unverändert. `chmod` darf nur der
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
  `<uninstall>`, `<update>`, `<updateservers>`, die Menüeinträge einer
  Komponente in `<administration>`) oder **neue/geänderte
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
  `sudo -n -u <web_user>` auf (ohne `web_user` als der eigene Benutzer), oder
  im Ordner `tmp/` der Seite über
  `cli_command` (siehe [Testseiten in Containern](#testseiten-in-containern));
  die Ausgabe nennt `via jxt bridge`. Ohne Bridge
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
Arguments wie `jxt\:*`. Mit `cli_command` sind keine sudoers-Regeln nötig.

`jxt bridge status` zeigt außerdem, wie jxt die Joomla-CLI aufruft
(`Runs via`): per sudo als Webserver-Benutzer, als eigener Benutzer oder über
`cli_command`.

## Testseiten in Containern

Bei einer Seite in einem Container (DDEV, Docker Compose, …) kann das PHP des
Hosts die Joomla-CLI der Seite meist nicht ausführen: Ihm fehlen womöglich
PHP-Version oder Erweiterungen, und einen Datenbank-Host wie `db`, den es nur
zwischen den Containern gibt, kann es nicht auflösen. `cli_command` führt die
Joomla-CLI stattdessen im Container aus. Eine solche Seite ist meist eine von
mehreren [Testseiten](#testseiten), hier mit der id `example`:

```bash
vendor/bin/jxt site add example
vendor/bin/jxt config set joomla_root /home/user/sites/example --site=example
vendor/bin/jxt config set cli_command --site=example -- docker exec -u www-data example-joomla-1 php
vendor/bin/jxt config set cli_root /var/www/html --site=example
```

- **`cli_command`** ist der Befehl, der PHP im Container startet. jxt hängt
  `<cli_root>/cli/joomla.php` und die Argumente an und führt ihn ohne Shell und
  ohne sudo aus, für `jxt install`, `jxt bridge` und die Bridge in `jxt sync`.
  `web_user` wird dann nicht benutzt; ihn neben `cli_command` zu setzen, ist
  in den Befehlen, die die Joomla-CLI nutzen, ein Fehler;
  `jxt config set` warnt nur davor, so lassen sich die Schlüssel in beliebiger
  Reihenfolge ändern. Bei `jxt config set` gehört `--`
  vor den Befehl, sonst gelten Optionen wie `-u` als Optionen von jxt;
  `--site` steht vor dem `--`.
- **`joomla_root`** ist der Ordner der Seite auf dem eigenen Rechner,
  **`cli_root`** ihr Pfad im Container (Standard: `joomla_root`). Die Dateien
  der Seite müssen als Bind-Mount mit dem eigenen Rechner geteilt sein: jxt
  liest dort die installierten Manifeste, und `jxt sync` vergleicht mit ihnen.
  Seiten in einem Named Volume werden nicht unterstützt.
- **Dateien für die Joomla-CLI:** jxt legt die ZIP-Datei von `jxt install` und
  den Plan der Bridge im Ordner `tmp/` der Seite ab, den der Container als
  `<cli_root>/tmp` sieht, und entfernt sie danach. Dafür braucht man dort
  Schreibrechte; fehlen sie, sagt jxt, wie man sie vergibt.
- **Benutzer:** Die Dateien gehören dem, der PHP im Container ausführt. Ohne
  `-u` läuft `docker exec` als Standardbenutzer des Containers, in vielen
  Images root, sodass die Dateien der Erweiterung root gehörten.

Scheitert ein Aufruf, zeigt jxt den Befehl, damit man ihn von Hand ausprobieren
kann, z. B. mit dem Argument `list`. Andere Werkzeuge funktionieren genauso,
solange ein Befehl PHP im Container startet, z. B. `podman exec`.

### DDEV

DDEV führt PHP unter dem eigenen Benutzer aus, und die Dateien der Seite gehören
einem selbst. Der Web-Container heißt `ddev-<projekt>-web`, und `docker exec`
nutzt standardmäßig den eigenen Benutzer. Die Seite in `sites`:

```json
{
    "id": "example",
    "joomla_root": "/home/user/sites/example",
    "cli_command": ["docker", "exec", "ddev-example-web", "php"],
    "cli_root": "/var/www/html"
}
```

`["ddev", "exec", "-p", "example", "php"]` funktioniert ebenso; der
Projektname ist nötig, da jxt im Ordner der Erweiterung läuft, nicht in dem des
DDEV-Projekts. Liegt Joomla in einem Unterordner des Projekts (`docroot` von
DDEV), gehört er an beide Pfade. `jxt sync` schreibt die Dateien direkt; die
Bridge ist nicht nötig. `ddev stop` entfernt die Container; `docker exec`
meldet dann `No such container`.

### Offizielles Joomla-Image

Mit dem offiziellen Image `joomla` und Docker Compose ist die Seite z. B. mit
`./site:/var/www/html` eingebunden, Apache läuft als `www-data`, und die Dateien
gehören `www-data` (UID 33). Der Container heißt
`<projekt>-<dienst>-<nummer>`, und `docker exec` läuft standardmäßig als root,
deshalb ist `-u www-data` nötig. Die Seite in `sites`:

```json
{
    "id": "example",
    "joomla_root": "/home/user/sites/example/site",
    "cli_command": ["docker", "exec", "-u", "www-data", "example-joomla-1", "php"],
    "cli_root": "/var/www/html"
}
```

Der eigene Benutzer darf in der Seite nicht schreiben, auch nicht in `tmp/`.
Einmal als root im Container erlauben; das Sticky-Bit verhindert, dass andere
die eigenen Dateien löschen, und Joomla nutzt den Ordner weiter:

```bash
docker exec example-joomla-1 chmod 1777 /var/www/html/tmp
```

Danach die Bridge mit `jxt bridge add` installieren, damit `jxt sync` die
Dateien als `www-data` schreibt.

## jxt build – installierbare ZIP-Datei erstellen

```bash
vendor/bin/jxt build               # aus der Arbeitskopie
vendor/bin/jxt build --tag v1.0.0  # aus einem Git-Tag
```

Erstellt `dist/<element>-<version>.zip` (Templates:
`dist/tpl_<name>-<version>.zip`, Plugins:
`dist/plg_<gruppe>_<element>-<version>.zip`, Komponenten:
`dist/com_<name>-<version>.zip`) mit dem Manifest und allen darin
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

Erzeugt das Projekt zuerst Dateien, stehen die Befehle unter `prepare` (siehe
[Build-Schritte](#build-schritte)); `jxt build` führt sie vor dem Bauen aus.

## Build-Schritte

Manche Projekte erzeugen Dateien, bevor sie sich bauen lassen: CSS aus SCSS,
minifizierte Skripte oder ein Manifest mit eingesetzter Version. Die Befehle
stehen in der Projektkonfiguration unter `prepare`; jxt führt sie im
Projektordner vor `jxt build`, `jxt install`, `jxt check` und `jxt sync` aus:

```json
{
    "prepare": [
        {"run": ["npm", "run", "css"]},
        {
            "run": ["npm", "run", "minify"],
            "sync": ["npm", "run", "minify:copy"]
        },
        {"run": ["npm", "run", "images"], "sync": false}
    ]
}
```

- Ein Schritt ist ein Objekt mit `run` und optional `sync`, jeweils ein Befehl,
  geschrieben als Liste von Argumenten. Die Befehle laufen ohne Shell, wie bei
  `cli_command`; für Pipes, `&&` oder Platzhalter ausdrücklich
  `["sh", "-c", "…"]` schreiben. Längere Abläufe sind als Skript im Projekt
  besser aufgehoben (npm-Skript, PHP- oder Shell-Skript).
- `jxt build`, `jxt install` und `jxt check` führen immer `run` aus, denn
  dort geht es um das, was ausgeliefert wird. `jxt sync` führt stattdessen
  `sync` aus, wenn ein Schritt das hat, oder lässt den Schritt mit
  `"sync": false` aus, z. B. weil ein Watch-Prozess (`sass --watch`) die
  Dateien aktuell hält. `"sync": null` wird als mehrdeutig abgewiesen: Ohne
  `sync` läuft derselbe Befehl.
- Die Schritte laufen nacheinander, jeweils mit leerer Standardeingabe; ihre
  Ausgabe erscheint sofort, nach einer Zeile mit dem Befehl (`> npm run css`).
  Mit `--quiet` wird sie gesammelt und nur in der Fehlermeldung gezeigt. Ein
  Schritt, der scheitert (Exit-Code ungleich 0) oder sich nicht starten lässt,
  hält jxt an, bevor etwas gebaut, geprüft oder kopiert wird; `jxt check`
  endet dann mit `2`.
- `jxt install --zip` führt keine Schritte aus, da es nichts baut.
  `jxt sync --dry-run` führt sie aus, damit die Vorschau die erzeugten Dateien
  vergleicht.
- Unter sudo verweigert jxt die Schritte, da die erzeugten Dateien sonst root
  gehörten.

Die Build-Schritte laufen mit den eigenen Rechten, eine fremde `.jxt.json`
führt also Befehle auf dem eigenen Rechner aus, wie Composer- und
npm-Skripte. Vor dem Aufruf von jxt in einem geklonten Projekt `prepare`
ansehen; es ist der einzige Schlüssel der `.jxt.json`, der Befehle ausführt,
da die Testseiten samt `cli_command` nur in der eigenen globalen
Konfiguration stehen. Sicher wird ein geklontes Projekt dadurch nicht:
`composer install` führt die Skripte seiner `composer.json` aus, und
`vendor/bin/jxt` lädt den Composer-Autoloader des Projekts samt den
`autoload.files` seiner `composer.json`. In GitHub Actions erhalten Workflows
auf `pull_request` bei Pull Requests aus Forks keine Secrets; erst
`pull_request_target` führte deren Schritte mit Secrets aus.

**Ersatz für `jxt sync`:** Einen Schritt, der nur optimiert (Minifizieren,
Bilder komprimieren), kann für `jxt sync` ein schnellerer Schritt ersetzen,
der dieselben Dateien ohne die Optimierung erzeugt, sodass auf der Testseite
jede Datei unter dem Namen liegt, auf den die Erweiterung verweist. Kopieren
ist zuverlässiger als Löschen: `minify:copy` kopiert z. B. `style.css`
unverändert nach `style.min.css`. Der Ersatz überschreibt die erzeugten
Dateien in der Arbeitskopie; sind sie committet, zeigt Git sie als geändert,
bis das nächste `jxt build` oder `jxt install` sie neu erzeugt.

**Erzeugtes Manifest:** Wird das Manifest selbst erzeugt, z. B. mit
eingesetzter Version, zeigt `manifest` auf die erzeugte Datei. Die Schritte
laufen, bevor jxt das Manifest sucht:

```json
{
    "manifest": "build/mod_example.xml",
    "prepare": [{"run": ["php", "tools/build.php"]}]
}
```

**Git-Tags:** Mit `--tag` laufen die Schritte aus der `.jxt.json` des Tags im
temporären Ordner mit dem exportierten Tag; die Arbeitskopie bleibt unberührt,
und das Ergebnis hängt nicht von nicht committeten Änderungen ab. Dateien, die
Git nicht verfolgt, etwa `node_modules/` oder `vendor/`, fehlen dort; ein
Schritt installiert sie, z. B.
`{"run": ["sh", "-c", "test -d node_modules || npm ci"]}`. jxt exportiert nur
den Projektordner: Liegt die `package.json` im Repository darüber, erreichen
die Schritte sie mit `--tag` nicht.

**Prüfungen:** `jxt check` prüft die erzeugten Dateien wie alle anderen. Mit
Git lassen die EditorConfig-Prüfungen Dateien aus, die Git ignoriert, wie
meist bei erzeugten Dateien. `assets/version-changed` vergleicht Dateien über
Git mit dem letzten Release und sieht daher keine Änderungen an erzeugten
Dateien, die nicht committet sind.

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
README) bleibt Handarbeit. Zuerst laufen die
[Build-Schritte](#build-schritte), sodass erzeugte Dateien so geprüft werden,
wie sie ausgeliefert würden.

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
Warnungen), `2` bei falschem Aufruf, falscher Konfiguration, fehlerhaftem
Manifest oder gescheitertem Build-Schritt. Hinweise lassen die Prüfung nie
scheitern.

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
| `assets/uri` | die URI eines Assets passt zu keiner Datei der Erweiterung, aufgelöst wie in Joomla (ein reiner Dateiname eines Templates in `templates/<name>/`, mit `<inheritable>` oder `<parent>` in `media/templates/…`; externe URIs, Medien anderer Erweiterungen und Dateien, die ein Child-Template vom Parent übernimmt, entfallen); bei `user.css` und `user.js` von Templates, die Websitebetreiber selbst anlegen (wie in Cassiopeia), nur ein Hinweis | Warnung | Warnung |
| `assets/version-changed` | die Datei des Assets hat sich seit dem letzten Release-Tag geändert, seine `version` nicht | Hinweis | Hinweis, mit `--release` Fehler |
| `assets/version-coupled` | eine Version war beim letzten Release gleich der Erweiterungsversion, ist es jetzt aber nicht | – | Hinweis |
| `assets/baseline` | kein Git oder noch kein Release-Tag, Asset-Versionen werden nicht verglichen | Hinweis | Hinweis |
| `language/parse` | eine Sprachdatei lässt sich nicht lesen | Fehler | Fehler |
| `language/files` | eine Sprachdatei fehlt in einer Sprache, verglichen je Zielordner (wie in Joomla ist der Sprach-Tag vor dem Dateinamen, `de-DE.mod_example.ini`, optional) | Fehler | Fehler |
| `language/keys` | ein Schlüssel fehlt in einer Sprache | Fehler | Fehler |
| `language/undefined` | eine in PHP, XML, JS oder SQL (außerhalb von Kommentaren) verwendete Sprachkonstante ist nicht definiert; eine Konstante vor `.` oder `+` muss der Anfang eines definierten Schlüssels sein, der Schlüssel von `Text::plural()` darf auch nur mit Plural-Suffix (`_1`, `_MORE` …) definiert sein | Fehler | Fehler |
| `language/unused` | eine definierte Konstante steht nicht im Code außerhalb von Kommentaren (sie kann zur Laufzeit zusammengesetzt werden; Schlüssel, die mit einer verketteten Konstante oder dem Schlüssel von `Text::plural()` beginnen, Name der Erweiterung, `<name>` des Manifests, Template-Positionen und Layouts, auch Layout-Overrides von Templates, werden nie gemeldet) | Hinweis | Hinweis |
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

## jxt site – Testseiten verwalten

```bash
vendor/bin/jxt site                         # Seiten, default_site und gewählte Seite zeigen
vendor/bin/jxt site add <id> [--default]    # Seite anlegen, mit --default als default_site
vendor/bin/jxt site remove <id>             # Seite entfernen
vendor/bin/jxt site rename <id> <new id>    # Seite umbenennen
```

Seiten werden nur mit `jxt site` angelegt, entfernt und umbenannt, damit ein
Tippfehler in `--site` keine Seite anlegt; ihre Schlüssel setzt
[jxt config](#jxt-config--konfiguration-anzeigen-und-ändern). `add` legt eine
Seite an, die nur ihre id hat. `remove` entfernt auch `default_site`, wenn es
auf die Seite verweist, `rename` ändert es mit der Seite. Verweise außerhalb
der globalen Konfiguration, z. B. `JXT_SITE` in einer `.envrc` oder `--site`
in einem Skript, bleiben unverändert; jxt weist darauf hin, und ein
vergessener Verweis scheitert beim nächsten Aufruf, da eine unbekannte Seite
ein Fehler ist. Die Liste markiert Seiten ohne `joomla_root` und mit
widersprüchlichen Schlüsseln. Wie `jxt config` verweigert `jxt site` unter
sudo das Schreiben.

```
Id      joomla_root
local   /var/www/html            default_site
example /home/user/sites/example selected via JXT_SITE
j5      –                        joomla_root missing
```

## jxt config – Konfiguration anzeigen und ändern

```bash
vendor/bin/jxt config                               # Werte, Herkunft und Testseite zeigen
vendor/bin/jxt config set <key> <value> [--global]  # Wert setzen
vendor/bin/jxt config set <key> <value>...          # Liste setzen, auch mit Komma
vendor/bin/jxt config set cli_command -- <command>  # Befehl setzen, Argumente unverändert
vendor/bin/jxt config set prepare -- <command>      # einen Build-Schritt setzen
vendor/bin/jxt config unset <key> [--global]        # Wert entfernen
```

Ohne `--global` ändern `set` und `unset` die Projektdatei `.jxt.json`, mit
`--global` die globale Datei `~/.config/jxt/config.json`. Die Schlüssel einer
Testseite (`joomla_root`, `web_user`, `cli_command`, `cli_root`) ändern immer
die gewählte [Testseite](#testseiten) in der globalen Datei: die von `--site`,
sonst die von `JXT_SITE`, sonst die von `default_site`. Die Seite muss
existieren, siehe [jxt site](#jxt-site--testseiten-verwalten); ihre id ändert
`jxt site rename`. `default_site` wird wie andere globale Schlüssel gesetzt,
z. B. `jxt config set default_site example`, und muss eine vorhandene Seite
nennen. `set <key> null`
speichert ein ausdrückliches `null`, z. B. um in `.jxt.json` einen globalen Wert
zu überschreiben; entfernt `unset` den letzten Schlüssel einer Datei, wird die
Datei gelöscht. Bei Listen nimmt `set` mehrere Werte, auch mit Komma getrennt,
und ersetzt die ganze Liste; `cli_command` behält seine Argumente unverändert,
ohne Trennung an Kommas. `set prepare` ersetzt die Build-Schritte durch einen
einzelnen Schritt mit `run`, dessen Argumente ebenso unverändert bleiben;
mehrere Schritte und Schritte mit `sync` stehen direkt in der `.jxt.json`.
`jxt config` ohne sudo aufrufen, sonst gehörte die Datei root; unter sudo
verweigert es das Schreiben.

```
Key           Value                            Origin
manifest      –                                not set
prepare       –                                not set
dist_dir      dist                             default
check_exclude assets/uri                       project
check_include –                                not set
default_site  local                            global
joomla_root   /home/user/sites/example         site
web_user      –                                not set
cli_command   docker exec ddev-example-web php site
cli_root      /var/www/html                    site

Test sites
local, example
current site: example via JXT_SITE

Configuration files
global:  /home/user/.config/jxt/config.json
project: /home/user/projects/mod_example/.jxt.json
```

Die Tabelle enthält die Schlüssel der aktuellen Seite, also der von `--site`,
`JXT_SITE` oder `default_site`. Die Herkunft (`Origin`) ist die Projektdatei
(`project`), die globale Datei (`global`), die aktuelle Seite (`site`),
der Standardwert (`default`) oder nicht gesetzt (`not set`). Widersprechen
sich die Schlüssel der Testseite, z. B. `web_user` neben `cli_command`, zeigen
`jxt config`, `jxt config set` und `jxt config unset` eine Warnung;
`jxt install`, `jxt bridge` und `jxt sync` über die Bridge scheitern, bis der
Widerspruch behoben ist. `jxt config` warnt auch, wenn keine Seite gewählt
ist, die gewählte nicht existiert oder ihr `joomla_root` fehlt.

## Konfiguration

Zwei JSON-Dateien werden zusammengeführt; Werte der Projektdatei überschreiben
die globalen.

| Datei | Zweck |
|---|---|
| `~/.config/jxt/config.json` | Globale Einstellungen und die Testseiten |
| `.jxt.json` im Projektordner | Einstellungen des Projekts (optional) |

Unter sudo wird die globale Datei aus dem Home-Verzeichnis des Benutzers
gelesen, der sudo aufgerufen hat (`SUDO_USER`), nicht aus `/root`.

| Schlüssel | Bedeutung | Standard |
|---|---|---|
| `sites` | Die Testseiten, siehe [Testseiten](#testseiten); nur in der globalen Datei | – (für `jxt install`, `jxt sync` und `jxt bridge` erforderlich) |
| `default_site` | id der Seite, die ohne `--site` und `JXT_SITE` gilt; nur in der globalen Datei | – (eine Seite muss gewählt werden) |
| `manifest` | Manifest-Datei relativ zum Projektordner; nur in `.jxt.json` | – (wird im Projektordner gesucht) |
| `prepare` | Build-Schritte vor `jxt build`, `jxt install`, `jxt check` und `jxt sync`; siehe [Build-Schritte](#build-schritte); nur in `.jxt.json` | – |
| `dist_dir` | Zielordner von `jxt build` und `jxt install`, relativ zum Projekt oder absolut | `dist` |
| `check_exclude` | Liste der Prüfungen, Gruppen oder `all`, die `jxt check` auslässt; nur in `.jxt.json` | – |
| `check_include` | Liste der Prüfungen, Gruppen oder `all`, die `jxt check` trotz `check_exclude` ausführt; nur in `.jxt.json` | – |

Alle Werte sind Texte, Listen von Texten (`check_exclude`, `check_include`),
`null` oder bei `prepare` und `sites` Listen von Objekten. `null` setzt einen
Schlüssel auf seinen Standardwert zurück. Unbekannte Schlüssel werden
abgewiesen, damit Tippfehler auffallen.

### Testseiten

Die Testseiten stehen nur in der globalen Datei, da sie den eigenen Rechner
beschreiben, nicht das Projekt, und `cli_command` Befehle ausführt. Jede Seite
ist ein Objekt in der Liste `sites`:

| Schlüssel | Bedeutung | Standard |
|---|---|---|
| `id` | Kennung der Seite (Buchstaben, Ziffern, `_`, `.`, `-`), unter den Seiten eindeutig | – (erforderlich) |
| `joomla_root` | Absoluter Pfad der Joomla-Seite; muss `configuration.php` enthalten | – (erforderlich) |
| `web_user` | Benutzer, als der `jxt install` und die jxt-Bridge die Joomla-CLI per sudo ausführen, z. B. `www-data`; nicht mit `cli_command` | – (die Joomla-CLI läuft als der eigene Benutzer, ohne sudo) |
| `cli_command` | Befehl, der PHP für die Joomla-CLI startet, z. B. in einem Container, statt sudo; siehe [Testseiten in Containern](#testseiten-in-containern) | – |
| `cli_root` | Pfad von `joomla_root`, wie `cli_command` ihn sieht; nur mit `cli_command` | `joomla_root` |

Beispiel `~/.config/jxt/config.json`:

```json
{
    "default_site": "local",
    "sites": [
        { "id": "local", "joomla_root": "/var/www/html", "web_user": "www-data" },
        {
            "id": "leaflet",
            "joomla_root": "/home/user/ddev/leaflet",
            "cli_command": ["ddev", "exec", "-p", "leaflet", "php"],
            "cli_root": "/var/www/html"
        }
    ]
}
```

Eine Seite ist in sich vollständig und übernimmt nichts von einer anderen.
`jxt install`, `jxt sync` und `jxt bridge` nutzen die mit `--site=<id>`
angegebene Seite, sonst die in der Umgebungsvariable `JXT_SITE` genannte,
sonst `default_site`; ein leeres `JXT_SITE` gilt als nicht gesetzt.
`default_site` ist optional: Ohne braucht jeder Aufruf `--site` oder
`JXT_SITE`. Eine Seite, die es nicht gibt, ist ein Fehler, jxt weicht nie auf
eine andere Seite aus. Stammt die Seite aus `--site` oder `JXT_SITE`, nennt
die Ausgabe sie, z. B. `mod_example → /home/user/ddev/leaflet (site "leaflet"
via JXT_SITE)`. `jxt build` und `jxt check` nutzen keine Seite.

Die Seite `local` des Beispiels richtet man so ein:

```bash
vendor/bin/jxt site add local --default
vendor/bin/jxt config set joomla_root /var/www/html
vendor/bin/jxt config set web_user www-data
```

Für ein Projekt, das immer eine andere Seite nutzt, `JXT_SITE` in seine
`.envrc` schreiben (siehe [jxt ohne vendor/bin aufrufen](#jxt-ohne-vendorbin-aufrufen));
ein Git-Worktree, z. B. für einen Bugfix an einer älteren Version auf einer
eigenen Seite, bekommt eine eigene `.envrc`:

```bash
PATH_add vendor/bin
export JXT_SITE=leaflet
```

In der `.jxt.json` lässt sich die Seite nicht wählen: Die ids sind eigene
Bezeichnungen und gehören nicht in ein Repository.

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
ungefragt Code ausführen oder eine [Testseite](#testseiten) wählen. `.envrc` ist eine persönliche Einstellung und gehört
nicht ins Repository, z. B. über die globale Git-Ignore-Datei
`~/.config/git/ignore`.
