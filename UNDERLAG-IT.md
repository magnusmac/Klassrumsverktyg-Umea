# Klassrumsverktyg – underlag till IT-avdelningen

**Till:** IT-avdelningen, Umeå kommun
**Från:** Magnus Macneil
**Gäller:** Förfrågan om serverplats för ett internt undervisningsverktyg

---

## Sammanfattning

Klassrumsverktyg är en enkel webbaserad digital whiteboard för klassrummet
– timer, slumpa elev, gruppindelning, QR-kod, PDF att skriva på med mera.
Det är tänkt att ersätta externa, molnbaserade tjänster av samma typ som i
dag används i skolan utan att elevdata stannar inom kommunen.

**Det jag ber om:** en plats att köra applikationen på inom kommunens egen
IT-miljö – i praktiken en liten virtuell server eller en containerplattform.
Applikationen är byggd för att kunna driftas helt utan internetanslutning
och utan beroenden till externa leverantörer.

**Varför det är intressant ur dataskyddssynpunkt:** ingen data lämnar
kommunens miljö, ingen tredjelandsöverföring sker, och inget
personuppgiftsbiträdesavtal med extern leverantör behöver tecknas eller
följas upp.

---

## 1. Vad verktyget gör

Läraren skapar en digital whiteboard som visas på projektor eller
interaktiv skärm i klassrummet. På tavlan placeras "widgets":

| Kategori | Widgets |
|---|---|
| Tid | Klocka, analog klocka, timer, stoppur |
| Arbetsro & rutiner | Trafikljus, att göra-lista, brain breaks |
| Elevaktivering | Namnsnurra (slumpa elev), gruppindelning, tärning, omröstning |
| Innehåll | Text, bild, PDF att rita och skriva på, QR-kod, YouTube, inbäddad presentation |

Tavlan kan delas med eleverna via en kort kod, med valfritt lösenordsskydd.

## 2. Varför det behövs

Motsvarande funktioner köps i dag typiskt in som molntjänst från externa
leverantörer (t.ex. Classroomscreen och liknande). Det innebär
återkommande licenskostnader, att elevrelaterad information hanteras hos
extern part, samt att verktygen slutar fungera vid nätverksavbrott.

Det här verktyget är utvecklat för kommunens egna behov, är fritt att
använda och ändra (MIT-licens), och kräver ingen licenskostnad.

## 3. Vad jag konkret ber om

En plats att köra applikationen på. Resursbehovet är litet:

| Resurs | Behov |
|---|---|
| Operativsystem | Valfri Linux-distribution |
| Programvara | PHP 8.x + Apache/nginx, MariaDB eller MySQL |
| Alternativt | Docker/Podman – färdig `docker-compose.yml` finns i projektet |
| CPU / RAM | 1–2 vCPU, 2 GB RAM räcker för en skola |
| Lagring | 10–20 GB (mest uppladdade PDF-filer) |
| Nätverk | HTTPS internt. Kan köras helt utan internetåtkomst |
| E-post | SMTP-relä för kontoaktivering och lösenordsåterställning |

Applikationen har inga externa körtidsberoenden – samtliga bibliotek
(Tailwind CSS, PDF.js, interact.js m.fl.) ligger lokalt i projektet. Inga
anrop görs till CDN:er, analysverktyg eller tredjepartstjänster vid
användning.

Källkoden finns här:
**https://github.com/magnusmac/Klassrumsverktyg-Umea**

## 4. Personuppgifter

Nedan är en fullständig kartläggning av vad applikationen lagrar.

### 4.1 Lärarkonton (`users`)

Namn, användarnamn, e-postadress, ev. skola, roll samt tidpunkt för
senaste inloggning. Lösenord lagras hashade (bcrypt), aldrig i klartext.
Tvåfaktorsautentisering finns för administratörskonton.

### 4.2 Elevuppgifter – begränsat och lärarstyrt

Applikationen har **ingen elevinloggning och inget elevregister**. Elever
importeras inte, och inga uppgifter hämtas från andra system.

Elevers **förnamn kan förekomma** där läraren själv skriver in dem, i två
funktioner:

- **Gruppindelning** – lärarens namnlista sparas per tavla (`student_groups`)
- **Namnsnurra** – namnlistan sparas i widgetens inställningar (`widgets.settings`)

Detta är fritextfält. Rekommendationen till lärare är att använda enbart
förnamn. Inga personnummer, betyg, omdömen, kontaktuppgifter eller
uppgifter om hälsa eller behov hanteras någonstans i systemet.

### 4.3 Omröstningar – anonyma

Elevers röster lagras **enbart som en räknare per svarsalternativ**. Varken
namn, IP-adress, webbläsare eller tidsstämpel per röst sparas, och det går
alltså inte att koppla en röst till en enskild elev – inte heller i
efterhand eller genom databasåtkomst.

### 4.4 Uppladdat material

Lärare kan ladda upp PDF-filer och bakgrundsbilder. Innehållet styrs helt
av läraren. Detta bör omfattas av samma interna riktlinjer som gäller för
annat undervisningsmaterial.

### 4.5 Gallring

Tavlor har ett utgångsdatum. Ett schemalagt skript (`cron/cron_cleanup.php`)
raderar utgångna tavlor tillsammans med tillhörande widgets, namnlistor och
omröstningar. Gallringsintervallet är konfigurerbart.

## 5. Dataskyddsrättsliga förutsättningar

Följande är min bedömning som verksamhetsföreträdare – den slutliga
bedömningen görs givetvis av kommunens dataskyddsombud.

**Personuppgiftsansvarig** är Umeå kommun genom ansvarig nämnd. Ingen
extern part är personuppgiftsbiträde, eftersom applikationen driftas i
kommunens egen miljö.

**Rättslig grund** bedöms vara artikel 6.1 e – behandling som är nödvändig
för att utföra en uppgift av allmänt intresse. Undervisning är en
lagstadgad uppgift enligt skollagen. Behandlingen bygger alltså inte på
samtycke från vårdnadshavare.

**Inga särskilda kategorier** av personuppgifter (artikel 9) behandlas.

**Dataminimering** är inbyggd: systemet efterfrågar inga elevuppgifter alls
för att fungera. Namnfunktionerna är valfria och kan användas med enbart
förnamn eller helt anonymiserat ("Elev 1", "Elev 2").

**Överföring till tredjeland sker inte.** Detta är den huvudsakliga
skillnaden mot molnbaserade alternativ, där leverantören ofta är
utomeuropeisk och där bedömningen av tredjelandsöverföring återkommande
måste omprövas.

**Kvarstår att göra tillsammans med er och dataskyddsombudet:**

1. Införa behandlingen i kommunens registerförteckning (artikel 30)
2. Bedöma om en konsekvensbedömning (DPIA) krävs – min bedömning är att
   behandlingens begränsade omfattning talar emot, men den rör barn, vilket
   är ett skäl att ta ställning uttryckligen
3. Fastställa gallringsfrister och koppla dem till kommunens
   dokumenthanteringsplan
4. Ta fram en kort instruktion till lärare om vad som får skrivas in

## 6. Säkerhet

Följande är på plats i dagsläget:

- Lösenordshashning med bcrypt
- Tvåfaktorsautentisering för administratörer
- Databasfrågor via parametriserade prepared statements
- Behörighetskontroll på alla API-anrop, kopplad till aktiv session
- Valfritt lösenordsskydd per tavla
- Möjlighet att begränsa vilka IP-intervall som får skapa tavlor
- Konfigurerbart krav på inloggning för att skapa tavlor

**Jag efterfrågar uttryckligen er granskning.** Applikationen har inte
genomgått någon extern säkerhetsgranskning, och jag vill hellre att brister
hittas av er innan den används skarpt än att den tas i drift ogranskad.
Källkoden är öppen och kan granskas i sin helhet.

Rekommenderat före driftsättning:

- HTTPS med kommunens certifikat
- Åtkomst begränsad till kommunens nät eller via befintlig VPN-lösning
- Backup av databasen enligt gällande rutin
- Loggning och uppdateringsrutin enligt kommunens standard

## 7. Förslag på nästa steg

1. Ett kort möte där jag visar verktyget i praktiken (15–20 minuter)
2. Ni tar ställning till drifts- och säkerhetsfrågorna ovan
3. Pilotdrift i en klass eller på en skola under en termin
4. Utvärdering tillsammans med dataskyddsombudet inför eventuell
   bredare användning

Jag är beredd att själv sköta den löpande utvecklingen av applikationen och
anpassa den efter de krav ni ställer.

---

**Kontakt:** Magnus Macneil
**Källkod:** https://github.com/magnusmac/Klassrumsverktyg-Umea
**Licens:** MIT (fri att använda, ändra och driftsätta)
