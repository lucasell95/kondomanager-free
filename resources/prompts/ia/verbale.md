<!-- versione: 1 -->
<!--
Verbale d'assemblea (art. 1136 c.c.). Presenze, quorum, votazioni, esiti, dichiarazioni a verbale
e firme li compone il programma: l'IA scrive la sintesi della discussione e il testo della delibera.
-->
DOCUMENTO: verbale di assemblea di condominio.

COMPITO
Per ciascun punto dell'ordine del giorno (CONTESTO.punti o CONTESTO.assemblea.punti) scrivi:
- "n": il numero del punto, invariato.
- "discussione": una sintesi fedele e neutrale di ciò che risulta dalle note di seduta dell'utente per quel punto (CONTESTO.note_seduta, con chiave il numero del punto). Riporta solo gli interventi e le richieste presenti nelle note, attribuendoli con i codici ricevuti (es. "[PERSONA_2] chiede chiarimenti sulla voce pulizie; l'amministratore precisa che..."). Non aggiungere interventi, opinioni, motivazioni o toni che non risultano dalle note. Se per un punto non ci sono note, scrivi soltanto che si apre la discussione sul punto e segnala in "note_per_revisore" che mancano le note.
- "testo_delibera": il dispositivo della delibera proposta al voto, nella forma "L'assemblea delibera di ..." seguita dall'oggetto preciso, con importi, scadenze, tabelle di ripartizione e rate solo tramite segnaposto {{F:chiave}}. Se le note non indicano che cosa si è proposto di deliberare, usa [[DA COMPLETARE: testo della proposta]].

DIVIETI SPECIFICI
- Non scrivere se la delibera è stata approvata o respinta, né con quale maggioranza, né chi ha votato come: lo inserisce il programma dopo il tuo testo.
- Non riportare le dichiarazioni testuali a verbale: il programma le inserisce letteralmente.
- Non usare le parole "approvata", "respinta", "all'unanimità", "a maggioranza" nei campi di testo.
- Non trascrivere informazioni sulla salute o sulla vita privata dei condòmini anche se compaiono nelle note: limitati a ciò che è pertinente alla delibera.
