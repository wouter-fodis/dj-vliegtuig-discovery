# DJ VLIEGTUIG DISCOVERY: overdracht

Zijproject van DJ VLIEGTUIG RADIO (`wouter-fodis/vlieg`, live op https://fodis.nl/fly/). Zelfde basis: het vliegtuig dat boven je komt, bepaalt het land. Maar in plaats van radio of volkslied ontdek je hier nieuwe muziek: elk vliegtuig brengt een artiest uit zijn land mee, in het genre dat jij kiest.

Live (zodra de cron draait): https://fodis.nl/dj-vliegtuig-discovery/ · Repo: `wouter-fodis/dj-vliegtuig-discovery` (publiek, geen geheimen erin).

**Wijzigingen voor Discovery horen in deze repo, niet in `vlieg`.**

## Bestanden

| Bestand | Wat |
|---|---|
| `index.html` | De hele app: HTML, CSS en JS in één bestand, geen build-stap. |
| `proxy.php` | Haalt alle externe data op namens de browser (CORS), met cache en een whitelist van hosts. Houdt MusicBrainz op maximaal 1 verzoek per seconde. |
| `deploy/deploy-discovery.php` | Deployscript. Hoort op de server in `/home/fodis/deploy-discovery.php`. |

## Afspraken in de code

Dezelfde als bij RADIO:
- **`index.html` is puur ASCII.** Speciale tekens als `\uXXXX` in JS of `&#NNN;` in HTML.
- **Alle UI-tekst via `t('sleutel', {vars})`**, woordenboek `I18N` met `nl`, `en`, `de`. Statische HTML via `data-i18n` en `data-i18n-attr`.
- **Noem een lokale variabele nooit `t`.** Gebruik `tgt` voor `anthemTarget(...)`.
- Commentaar in het Nederlands.

## Hoe Discovery werkt

1. **De dj:** het dichtstbijzijnde toestel binnen je straal met een bekende route. Land via `anthemTarget` (vertrekker: bestemming; lander: herkomst). Het land blijft draaien tot er een toestel uit een ander land binnen je straal komt.
2. **Artiest zoeken** (`artistsFor`): MusicBrainz-zoekopdracht `country:XX AND tag:"genre"`, eerste 100 plus soms een willekeurige diepere pagina, zodat je ook onbekende artiesten krijgt.
3. **Fragment** (`trackFor`): iTunes Search API, alleen treffers waarvan de artiestnaam precies overeenkomt en die een `previewUrl` hebben (30 s). Eerst de winkel van dat land, dan de VS. Dit levert titel, hoes en het reservefragment.
3b. **Heel nummer** (`youtubeFor`): YouTube Data API-zoekopdracht "artiest titel" (embeddable, categorie Muziek), treffer moet titel en artiest bevatten. Afspelen via de YouTube IFrame-speler in de kaart (`ensurePlayer`, `playYT`). Geen video, foutmelding van de speler, of start hij niet binnen 6 s vanzelf (iPhone)? Dan speelt het iTunes-fragment (`playAudio`) met de melding "Tik op de video". Tikt iemand op de video, dan neemt die het over (`ytState`). Faalt de zoekopdracht (geen sleutel, quotum op), dan tien minuten alleen fragmenten (`ytOffUntil`).
4. **Volgorde** (`discover`): nieuwe artiest in jouw genre, anders iets anders uit dat land (met melding), anders mag een eerder gehoorde artiest terugkomen.
5. **Doorspelen:** is een nummer of fragment af, dan volgt de volgende artiest uit hetzelfde land. Twee audio-elementen voor crossfades (iOS: harde wissel, volume werkt daar niet).
6. **Klaarzetten** (`prefetch`/`ready`): voor het toestel op het bord wordt alvast een artiest gezocht. Dat zie je bij "Hierna:".
7. **Spotify zonder login:** de MusicBrainz-link naar het Spotify-artiestprofiel (`url-rels`) geeft een knop "Luister verder in Spotify" met de artiest-embed. Wie in de browser bij Spotify is ingelogd, hoort daar hele nummers, anders fragmenten. Zonder link is er een zoeklink naar Spotify. Zolang de embed open is, staat de dj uit; "Terug naar live" zet hem weer aan.
8. **Bewaren:** ♥ bewaart een nummer in `localStorage` (`discFavs`), alleen in die browser. "Kopieer lijst" zet "Artiest – Titel"-regels op het klembord.
9. Genre in `localStorage` (`discGenre`), lijst `GENRES` (MusicBrainz-tags).

## Waarom geen Spotify-API

Spotify heeft sinds november 2024 aanbevelingen, verwante artiesten en preview-URL's dichtgezet voor nieuwe apps. Sinds 2026 mag een app in Development Mode maar 5 gebruikers hebben, met Premium. Daarom MusicBrainz plus iTunes, zonder login.

## Databronnen (via `proxy.php`)

Alles van RADIO (vluchten, routes, Wikidata, Wereldbank, Frankfurter, Big Mac, Apple Music-hitlijsten, Nominatim, Overpass), plus:

| Bron | Waarvoor | Cache |
|---|---|---|
| musicbrainz.org | Artiesten per land en genre, Spotify-link per artiest | 1 dag, max 1 verzoek/s |
| itunes.apple.com | Titel, hoes, reservefragment van 30 s, link naar Apple Music | 1 dag |
| www.googleapis.com (alleen `/youtube/v3/search`) | Video-ID voor het hele nummer | 1 week |

Radio-browser en de ICY-radiotekst zijn eruit gehaald.

**YouTube-sleutel:** staat NIET in de repo. `proxy.php` leest hem uit `/home/fodis/.discovery-youtube-key` (één regel) en plakt hem achter de zoekopdracht; de cache gebruikt de URL zonder sleutel. Zonder sleutelbestand geeft de proxy 503 en speelt de app fragmenten. Quotum: 10.000 eenheden per dag, een zoekopdracht kost er 100, dus ongeveer 100 nieuwe zoekopdrachten per dag voor alle gebruikers samen (de weekcache helpt).

**Risico:** de iTunes Search API staat ongeveer 20 verzoeken per minuut per IP toe, en alles gaat via het IP van de server. Bij veel gebruikers tegelijk kan dat knellen. Oplossing als het nodig is: iTunes rechtstreeks vanuit de browser aanroepen (die API ondersteunt JSONP), dan telt het per gebruiker.

## Deploy

Hetzelfde principe als RADIO, maar een eigen script en eigen map:
1. Zet `deploy/deploy-discovery.php` op de server als `/home/fodis/deploy-discovery.php`.
2. Cronjob (DirectAdmin, gebruiker `fodis`), elke minuut:
   `/usr/local/bin/php -q /home/fodis/deploy-discovery.php >> /home/fodis/deploy-discovery.log 2>&1`
3. Het script maakt `public_html/dj-vliegtuig-discovery/` zelf aan en zet daar `index.html` en `proxy.php` neer. Laatste commit in `/home/fodis/.deploy-discovery-commit`.

## Testen

Playwright (Python, Chromium in de werkomgeving) met nagemaakte antwoorden voor `proxy.php?url=...` (adsb, adsbdb, musicbrainz, itunes) en een nep-audiobestand. Getest: eerste vliegtuig, volgende artiest, bewaren, overname door een ander land, genre zonder treffer, genrewissel, nummer opnieuw uit de speellijst, Spotify-embed en terug naar live, geluid uit, Engelse browser, telefoonbreedte.

**Nog niet getest tegen de echte MusicBrainz- en iTunes-API's** (de werkomgeving kon er niet bij). Eerste live controle: kijk of artiesten en fragmenten echt binnenkomen.

## Openstaande ideeën

- iTunes vanuit de browser aanroepen (zie risico).
- Bewaarde nummers exporteren naar een Spotify-playlist (vraagt wél login).
- Hitlijst-tab (Apple Music top 5) bevat nu nog de RADIO-tekst; eventueel vervangen door "meer van deze artiest".
