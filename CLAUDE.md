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
3a. **Eerst Wikidata** (`wdArtists`): per land en genre (P136 + subgenres via P279*, root = muziekgenre Q188451 met label = genre) alleen artiesten die officieel te beluisteren zijn: SoundCloud-account (P3040) of Spotify-artiest (P1902). Ook platenlabel (P264, getoond als "label: …") en aantal Wikipedia-talen (`wikibase:sitelinks`). Mislukt de zoekopdracht (>25 s in de proxy), dan deze sessie de MusicBrainz-route.
3b. **Hoe bekend?** (`fame`, 0–4, `FAME_STEPS` = minimaal 0/1/3/8/20 Wikipedia-talen, `localStorage` `discFame`, deelbaar met `fame=`). Vanaf stand 2 geen MusicBrainz-route meer (daar is bekendheid onbekend).
3c. **Afspelen, alleen geluid** (geen YouTube meer: video leidde af). Per nummer een lijst spelers (`enginesFor`), de eerste die werkt speelt (`nextEngine`):
   - `sp`: **Spotify** iFrame API (`open.spotify.com/embed/iframe-api/v1`), artiest-URI → populairste nummers. Alleen als de luisteraar "Ik heb Spotify" aanvinkt (`discSpotify`). Ingelogd bij Spotify in die browser = hele nummers; anders fragmenten van 30 s (gedetecteerd via duur ≤ 31 s → melding `spLogin`). Geen volumeregeling, dus harde wissel. Net voor het einde van het nummer: volgende artiest.
   - **Inloggen bij Spotify** (`spLogin`, `renderSpLogin`): knop "Log in bij Spotify" onder het vinkje. Opent `accounts.spotify.com/login?continue=open.spotify.com` in een pop-up (telefoon: nieuw tabblad). Spotify stuurt na inloggen niet terug (alleen naar open.spotify.com). Pop-up dicht, tabblad weer zichtbaar of nog een tik op de knop ("Ingelogd? Tik hier") → `spLoginDone` → `spReload` (speler opnieuw aanmaken, dan ziet hij de login). Status: heel nummer gehoord (duur > 31 s) → `spOk` (`localStorage` `discSpotifyOk`), knop verdwijnt; nog fragmenten → tip om Chrome te gebruiken. Werkt alleen als de browser cookies van derden toestaat (Chrome, Edge); Safari, iPhone en Firefox blokkeren dat meestal, daar blijven het fragmenten. **`SP_FULL`** (iPhone/iPad, ook Chrome daar, Safari, Firefox = false): geen inlogknop maar uitleg (`spNoFull`), en SoundCloud gaat dan vóór Spotify in `enginesFor`. Een echte OAuth-koppeling kan niet: Spotify staat in Development Mode maar 5 gebruikers toe.
   - `sc`: **SoundCloud**-widget (`w.soundcloud.com/player/api.js`) met het profiel van de artiest; willekeurig nummer uit de eerste 10. Hele nummers zonder login. Go+-fragmenten (`policy: 'SNIP'`), nummers < 1 min of > 15 min worden overgeslagen. Titel en hoes uit de widget. Volume-fades werken.
   - `audio`: iTunes-fragment (reserve, crossfades).
   Start een speler niet binnen 7 s (bijv. iPhone zonder tik), dan de volgende (`watch`, melding `tapPlayer`).
3d. SoundCloud-account via MusicBrainz (`url-rels`, `soundcloud.com/<naam>`) of Wikidata (P3040).
4. **Genres en strengheid:** `GENRE_GROUPS` (77 MusicBrainz-tags in 9 groepen), `GENRE_PARENT` (subgenre → bovenliggend genre), `GENRE_LABEL` (namen in nl/de waar die afwijken). Streng: het genre moet onder de **drie belangrijkste tags** van de artiest vallen (`fitsGenre`/`underGenre`: zelfde tag, tag die op het genre eindigt zoals "indie rock" → rock, of via `GENRE_PARENT`).
4b. **Periode:** schuif met twee knoppen (`yearFrom`/`yearTo`, 1900 tot nu, `localStorage` `discYears`). Artiest moet in die periode actief zijn (`fitsYears`: begin/einde uit MusicBrainz) en het iTunes-nummer moet in die jaren uitgebracht zijn. Let op: oude muziek staat bij iTunes vaak met de datum van een heruitgave, dus vroege periodes geven minder treffers.
4c. **Volgorde** (`discover`): nieuwe artiest in jouw genre, dan in het bovenliggende genre (melding "dit is {p}"), daarna mag een eerder gehoorde artiest terugkomen. Nooit meer een willekeurig ander genre. Niets gevonden: het land komt in `noMusic` en de app blijft bij het land dat al speelde (`musicLead`, melding "We blijven nog even bij …").
5. **Doorspelen:** is een nummer of fragment af, dan volgt de volgende artiest uit hetzelfde land. Twee audio-elementen voor crossfades (iOS: harde wissel, volume werkt daar niet).
6. **Klaarzetten** (`prefetch`/`ready`): voor het toestel op het bord wordt alvast een artiest gezocht. Dat zie je bij "Hierna:".
7. **Spotify zonder login:** de MusicBrainz-link naar het Spotify-artiestprofiel (`url-rels`) geeft een knop "Luister verder in Spotify" met de artiest-embed. Wie in de browser bij Spotify is ingelogd, hoort daar hele nummers, anders fragmenten. Zonder link is er een zoeklink naar Spotify. Zolang de embed open is, staat de dj uit; "Terug naar live" zet hem weer aan.
8. **Bewaren:** ♥ bewaart een nummer in `localStorage` (`discFavs`), alleen in die browser. "Kopieer lijst" zet "Artiest – Titel"-regels op het klembord. "Mail lijst" (`mailLink`) is een `mailto:`-link: het eigen mailprogramma opent met onderwerp en lijst ingevuld (artiest – titel, land, YouTube- of Spotify-link), ingekort tot ~1200 tekens. Bewust geen verzending via de server: dan kan iedereen via fodis.nl mail naar willekeurige adressen sturen, en zulke mail belandt vaak in de spam.
9. Genre in `localStorage` (`discGenre`).
10. **Menu** bovenin: RADIO (/fly/) en DISCOVER, met slogan per taal (`tagRadio`, `tagDiscover`). Staat ook in de RADIO-app.
11. **Delen** (`shareLink`, `applyShared`): knop "Deel" maakt een link met `spot=<baankop-id>` of `at=lat,lon` (afgerond op 3 decimalen, ~100 m) plus `name`, `r` (straal), `genre` en `years=1985-1999`. Een gedeelde link gaat voor op opgeslagen instellingen. Op telefoons het deelmenu van het toestel, anders naar het klembord.
13. **Banen op de radar:** elke baankop is een stip met de kopnaam en is klikbaar (`radarHits`, klik → `selectSpot`). **In gebruik** (`trackRunways`, elke seconde): kwam er de laatste 10 minuten een toestel onder 2500 ft binnen 1,5 km over de kop, in de lijn van de baan, dan is de stip geel en staat er "in gebruik" achter de baan in de keuzelijst. Vertrekkers die over de andere kop klimmen tellen ook mee voor die kop.
12. **Layout:** linkerkolom vlucht, radar, landinfo (radar boven de landinfo).

## Waarom geen Spotify-API

Spotify heeft sinds november 2024 aanbevelingen, verwante artiesten en preview-URL's dichtgezet voor nieuwe apps. Sinds 2026 mag een app in Development Mode maar 5 gebruikers hebben, met Premium. Daarom MusicBrainz plus iTunes, zonder login.

## Databronnen (via `proxy.php`)

Alles van RADIO (vluchten, routes, Wikidata, Wereldbank, Frankfurter, Big Mac, Apple Music-hitlijsten, Nominatim, Overpass), plus:

| Bron | Waarvoor | Cache |
|---|---|---|
| musicbrainz.org | Artiesten per land en genre, Spotify- en SoundCloud-link per artiest | 1 dag, max 1 verzoek/s |
| query.wikidata.org | Artiesten met SoundCloud/Spotify, label, bekendheid | 1 dag, timeout 25 s |
| itunes.apple.com | Titel, hoes, reservefragment van 30 s, link naar Apple Music | 1 dag |

Radio-browser en de ICY-radiotekst zijn eruit gehaald.

YouTube is eruit (oktober 2026): de video's leidden af en de zoek-API had een dagquotum van ~100 zoekopdrachten. Het sleutelbestand `/home/fodis/.discovery-youtube-key` wordt niet meer gebruikt.

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
