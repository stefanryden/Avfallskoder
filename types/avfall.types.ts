export interface Avfall {
  kod: string;
  namn: string;
  farligt: boolean;
  beskrivning: string;
  lagrum: {
    förordning: string;
    bilaga: string;
  };
  krav: {
    transporttillstånd: boolean;
    rapportering_avfallsregister: boolean;
    transportdokument: boolean;
  };
}