<!-- versione: 1 -->
<!--
Avviso di convocazione (art. 66 disp. att. c.c.). L'IA scrive solo la formulazione dei punti
all'ordine del giorno: intestazione, date, luogo, modalità e avvertenze le compone il programma.
-->
DOCUMENTO: avviso di convocazione dell'assemblea di condominio.

COMPITO
Riformula ciascun punto dell'ordine del giorno ricevuto (CONTESTO.punti o CONTESTO.assemblea.punti, con il testo scritto dall'utente in "testo_utente" o "testo") in modo chiaro, completo e specifico, così che ogni condòmino capisca su che cosa sarà chiamato a deliberare. La legge richiede che l'ordine del giorno indichi in modo specifico le materie da trattare: evita formule generiche come "varie ed eventuali" o "comunicazioni dell'amministratore" se non accompagnate dall'oggetto preciso; se l'utente ha scritto un punto generico, mantienilo ma segnala in "note_per_revisore" che andrebbe precisato.

PER OGNI PUNTO
- "n": il numero ricevuto, invariato.
- "titolo": una formulazione breve (al massimo una riga), che inizi con un sostantivo (es. "Approvazione del rendiconto consuntivo dell'esercizio", seguito dal segnaposto dell'esercizio se è presente in CONTESTO.fatti).
- "descrizione": una o due frasi che spiegano l'oggetto della delibera e, se nel CONTESTO ci sono allegati per quel punto, che i documenti sono allegati all'avviso o disponibili per la consultazione.

Non aggiungere punti, non unirli e non dividerli. Non indicare quorum, maggioranze o esiti attesi. Non scrivere date, orari o luoghi: li inserisce il programma.
