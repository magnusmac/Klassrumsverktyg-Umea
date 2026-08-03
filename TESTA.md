# Testa appen – steg för steg

Det finns två sätt att testa Klassrumsverktyg utan att installera PHP
eller MySQL själv: **GitHub Codespaces** (helt i webbläsaren, inget
installeras på datorn) eller **Docker Desktop** (på din egen dator).

I båda fallen räcker det med **ett kommando**: `bash starta.sh`

---

## Alternativ A: GitHub Codespaces (rekommenderas på jobbdator)

1. Gå till repot på GitHub → klicka på gröna knappen **`<> Code`**
   → fliken **Codespaces** → **Create codespace**.
2. Vänta tills VS Code öppnats i webbläsaren (~2 min första gången).
3. Öppna terminalen (**View → Terminal** eller `Ctrl + ö`) och kör:
   ```
   git checkout claude/repo-development-9d6mr1
   bash starta.sh
   ```
4. Vänta tills det står *"Apache ... resuming normal operations"*.
5. Klicka på fliken **PORTS** längst ner → klicka på 🌐-ikonen vid
   port **8080** → appen öppnas i en ny flik.
6. Logga in med:

   | Fält      | Värde               |
   |-----------|---------------------|
   | E-post    | `admin@klassrum.se` |
   | Lösenord  | `admin123`          |

> **Obs:** En Codespace är tillfällig. Skapar du en ny senare är det
> bara att köra samma två kommandon igen – databasen med admin-kontot
> skapas automatiskt.

---

## Alternativ B: Docker Desktop på egen dator

1. Installera **Docker Desktop** från
   https://www.docker.com/products/docker-desktop/ och starta det.
2. Öppna en terminal (PowerShell på Windows) i projektmappen och kör:
   ```
   bash starta.sh
   ```
   *(Fungerar inte `bash` i PowerShell? Kör `wsl bash starta.sh`
   eller använd Git Bash.)*
3. Öppna http://localhost:8080 och logga in som ovan.

---

## Testa widgetarna

Skapa eller öppna en **whiteboard** och testa i sidofältet under
**WIDGETS**:

**Inbäddning** – klicka på "Ange länk" och klistra in en länk till en
Google Presentation → den visas direkt i widgeten.

**PDF** – klicka på "Ladda upp" i verktygslisten och välj en PDF.
Testa **penna**, **markeringspenna**, **text** (klicka på sidan),
**sudd**, färgerna och pilarna för att bläddra. Ladda om sidan –
anteckningarna ska finnas kvar.

**Namnsnurra** – klicka på "Lägg till namn", skriv ett namn per rad,
spara och klicka **Snurra!**

**Tärning** – välj antal tärningar och sidor, klicka **Kasta!**

**Stoppur** – starta, ta varvtider, nollställ.

---

## Stänga av / börja om

Stäng av: `Ctrl + C` i terminalen.

Börja om från noll (raderar databasen):
```
docker compose down -v
rm src/Config/Database.php
bash starta.sh
```

## Vanliga frågor

**"Port 8080 används redan"** – ändra `"8080:80"` i
`docker-compose.yml` till t.ex. `"8090:80"` och använd port 8090.

**Ingen 🌐-ikon vid porten i Codespaces?** – vänta tills Apache-raden
synts i terminalen och kolla igen under fliken PORTS.
