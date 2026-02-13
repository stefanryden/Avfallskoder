import fs from 'fs';
import path from 'path';

interface Lagrum {
  avfallsforordningen: {
    kapitel: Record<string, string>;
    förordning: string;
  };
  miljobalken: {
    kapitel: Record<string, string>;
    förordning: string;
  };
  nfs: {
    förordning: string;
    paragrafer: string[];
  };
}

export function parseLagrum(): Lagrum {
  return {
    avfallsforordningen: {
      kapitel: {
        "2": "Allmänna hänsynsregler",
        "6": "Anteckning och rapportering",
        "15": "Övergripande ansvar",
      },
      förordning: "Avfallsförordningen (2020:614)",
    },
    miljobalken: {
      kapitel: {
        "2": "Allmänna hänsynsregler",
        "15": "Avfall",
      },
      förordning: "Miljöbalken (1998:808)",
    },
    nfs: {
      förordning: "NFS 2020:5",
      paragrafer: ["6 kap. 1–5 §§", "6 kap. 11 §", "7 kap. 3 §"],
    },
  };
}

// Example usage
const lagrumData = parseLagrum();
fs.writeFileSync(
  path.join(__dirname, '../data/lagrum.json'),
  JSON.stringify(lagrumData, null, 2)
);