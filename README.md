# Avfallskoder Projekt

Detta projekt syftar till att skapa en sökfunktion för svenska avfallskoder (EWC-koder) med juridisk hänvisning. Verktyget är avsett för elnätsprojektörer, miljösamordnare och entreprenörer.

## Projektstruktur

```
/data
   avfallskoder.json
   lagrum.json
   kravregler.json
/parser
   parse_avfallskoder.ts
   parse_lagrum.ts
/types
   avfall.types.ts
```

### Datamodell

#### Avfallskoder
```json
{
  "kod": "170301*",
  "namn": "Tjärasfalt",
  "farligt": true,
  "beskrivning": "...",
  "lagrum": {
      "förordning": "Avfallsförordningen (2020:614)",
      "bilaga": "Bilaga 3"
  },
  "krav": {
      "transporttillstånd": true,
      "rapportering_avfallsregister": true,
      "transportdokument": true
  }
}
```

#### Lagrum
```json
{
  "avfallsforordningen": {
    "kapitel": {
      "2": "Allmänna hänsynsregler",
      "6": "Anteckning och rapportering",
      "15": "Övergripande ansvar"
    },
    "förordning": "Avfallsförordningen (2020:614)"
  },
  "miljobalken": {
    "kapitel": {
      "2": "Allmänna hänsynsregler",
      "15": "Avfall"
    },
    "förordning": "Miljöbalken (1998:808)"
  },
  "nfs": {
    "förordning": "NFS 2020:5",
    "paragrafer": ["6 kap. 1–5 §§", "6 kap. 11 §", "7 kap. 3 §"]
  }
}
```

## Funktioner

### `searchAvfall(term: string): AvfallResult[]`

Denna funktion möjliggör:
- Sökning via namn
- Sökning via kod
- Returnerar om avfallet är farligt eller ej
- Returnerar juridisk referens

## Uppdatering av lagdata

1. Uppdatera källfilerna i projektmappen.
2. Kör parser-skriptet i `/parser` för att generera nya JSON-filer.

## Ansvarsfriskrivning

Detta verktyg är inte avsett att användas som juridisk rådgivning. För juridiska frågor, vänligen kontakta en kvalificerad jurist.