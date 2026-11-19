# ATT Portaal — Schadebeheer wagenmappen

Dit werk hoort bij de live applicatie van november 2026. De versie op `main` is een oudere codebasis van juli 2026. Daarom geen wijzigingen uit deze branch rechtstreeks naar `main` mergen of naar de live server kopiëren.

## Functionele afspraken

- Horizontale mappen: Wagen 1, Wagen 2, Wagen 4, Wagen 5, Wagen 7, Wagen 8, Bus 11.
- Wagen 1 en Bus 11 zijn reserve en hebben geen verplicht minimum.
- Dag- en nachtstart: Wagen 2 07:00/19:00, Wagen 4 06:00/18:00, Wagen 5 06:30/18:30, Wagen 7 en 8 05:30/17:30.
- Eén ingediende check per ploeg, twee per operationele dag. Bewaar de werkelijke datum/tijd.
- Bij ontbrekende check na 30 minuten knippert alleen de betreffende wagenmap rood, geen push/e-mail/bel.
- Admin/Developer mogen het knipperen handmatig voor die dienst uitzetten. De check blijft als ontbrekend genoteerd.
- Bestaande checks, schade, foto's en historie behouden; losse archiefknop overbodig.

## Live installatie

De installatie is voorbereid als een zelfstandig Python-bestand met code- en databasecontroles en file-back-ups. Neem uitsluitend het bestand uit de oorspronkelijke ChatGPT-sessie en plaats het via SFTP op de VPS. Controleer de inhoud, voer het met `sudo python3` uit en verifieer de zeven voertuigkoppelingen in de browser. De installatie stopt als de huidige PHP-code is gewijzigd of de databaseklok niet overeenkomt met Europe/Brussels. Er wordt geen bestaande voertuigcheck of schade verwijderd.
