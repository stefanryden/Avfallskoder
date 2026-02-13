import fs from 'fs';
import path from 'path';
import { Avfall } from '../types/avfall.types';

export function parseAvfallskoder(filePath: string): Avfall[] {
  const fileContent = fs.readFileSync(filePath, 'utf-8');
  const lines = fileContent.split('\n');

  const avfallList: Avfall[] = [];

  lines.forEach((line) => {
    const match = line.match(/^(\d{2} \d{2} \d{2}\*?)\s+(.*)$/);
    if (match) {
      const [_, kod, namn] = match;
      const farligt = kod.includes('*');
      avfallList.push({
        kod: kod.replace(/\s/g, ''),
        namn,
        farligt,
        beskrivning: namn,
        lagrum: {
          förordning: "Avfallsförordningen (2020:614)",
          bilaga: "Bilaga 3",
        },
        krav: {
          transporttillstånd: farligt,
          rapportering_avfallsregister: farligt,
          transportdokument: farligt,
        },
      });
    }
  });

  return avfallList;
}

// Example usage
const avfallskoderPath = path.join(__dirname, '../data/avfallskoder.md');
const avfallData = parseAvfallskoder(avfallskoderPath);
fs.writeFileSync(
  path.join(__dirname, '../data/avfallskoder.json'),
  JSON.stringify(avfallData, null, 2)
);