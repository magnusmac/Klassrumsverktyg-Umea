# Testa appen lokalt – steg för steg

Den här guiden gör så att du kan köra hela Klassrumsverktyg på din egen dator
för att testa de nya widgetarna (Inbäddning och PDF). Du behöver **inte**
installera PHP eller MySQL själv – allt körs via Docker.

---

## 1. Installera Docker Desktop (görs en gång)

1. Gå till https://www.docker.com/products/docker-desktop/
2. Ladda ner och installera **Docker Desktop** för din dator (Windows eller Mac).
3. Starta Docker Desktop och vänta tills den säger att den är igång
   (en liten val-symbol uppe i hörnet blir grön/stabil).

---

## 2. Starta appen

1. Öppna en **terminal**:
   - **Windows:** öppna "PowerShell"
   - **Mac:** öppna "Terminal"
2. Gå till mappen där koden ligger. Exempel:
   ```
   cd sökväg/till/Klassrumsverktyg-Umea
   ```
3. Skriv detta kommando och tryck Enter:
   ```
   docker compose up
   ```
4. Första gången tar det några minuter (den laddar ner och bygger allt).
   När det står något i stil med *"Apache ... resuming normal operations"*
   är appen igång. **Låt fönstret vara öppet** medan du testar.

---

## 3. Installera databasen (görs en gång)

1. Öppna webbläsaren och gå till:
   ```
   http://localhost:8080/install/install.php
   ```
2. Fyll i installationsformuläret med **exakt** dessa värden:

   | Fält              | Värde         |
   |-------------------|---------------|
   | Databas-host      | `db`          |
   | Databasnamn       | `klassrum`    |
   | Databasanvändare  | `root`        |
   | Lösenord          | `klassrum123` |

3. Slutför installationen (skapa även en admin-användare när du blir ombedd –
   kom ihåg e-post och lösenord du väljer).

---

## 4. Logga in och testa widgetarna

1. Gå till `http://localhost:8080/` och logga in med admin-kontot du skapade.
2. Skapa eller öppna en **whiteboard**.
3. I sidofältet under **WIDGETS**, testa:

   **Inbäddning**
   - Klicka på "Inbäddning"
   - Klicka på pennan/redigera-ikonen i widgetens namnlist
   - Klistra in en länk till en Google Presentation → den ska visas direkt

   **PDF**
   - Klicka på "PDF"
   - Klicka på upp-ikonen (⬆) i namnlisten och välj en PDF-fil
   - Testa verktygen: **penna**, **markeringspenna**, **text** (klicka på sidan),
     **sudd**, färgerna och pilarna för att bläddra mellan sidor
   - **Ladda om sidan** i webbläsaren – dina anteckningar ska finnas kvar

---

## 5. Stänga av

I terminalfönstret: tryck `Ctrl + C`.
Vill du städa bort allt (inklusive databasen) kör du:
```
docker compose down -v
```

---

## Vanliga frågor

**"Det står att port 8080 redan används"**
Ändra `"8080:80"` i `docker-compose.yml` till t.ex. `"8090:80"` och använd
`http://localhost:8090/` istället.

**Jag vill börja om från noll**
Kör `docker compose down -v` och ta bort filen `src/Config/Database.php`.
Starta sedan om från steg 2.
