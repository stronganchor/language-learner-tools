from __future__ import annotations

from pathlib import Path

import polib


path = Path("languages/ll-tools-text-domain-de_DE.po")
po = polib.pofile(str(path))
changed = 0
missing: list[str] = []
ambiguous: list[str] = []


exact = {
    "Save %d processed audio file(s)?": "%d bearbeitete Audiodatei(en) speichern?",
    "Word cannot be empty.": "Die Vokabel darf nicht leer sein.",
    "Deleted %d recording(s).": "%d Aufnahme(n) gelöscht.",
    'Remove "%s" from this batch? It will remain unprocessed.': '"%s" aus diesem Stapel entfernen? Die Aufnahme bleibt unbearbeitet.',
    "Recording Type": "Aufnahmetyp",
    "Process uploaded audio recordings with configurable noise reduction, loudness normalization, and silence trimming.": "Verarbeite hochgeladene Audioaufnahmen mit konfigurierbarer Rauschunterdrückung, Normalisierung der Lautstärke und Entfernen von Stille.",
    "Insufficient permissions to edit the parent word.": "Unzureichende Berechtigungen zum Bearbeiten der übergeordneten Vokabel.",
    "Audio recording deleted": "Audioaufnahme gelöscht",
    "Failed to delete audio recording": "Audioaufnahme konnte nicht gelöscht werden",
    "Save API key": "API-Schlüssel speichern",
    "Resolved from each item’s word set settings.": "Wird aus den Vokabelset-Einstellungen des jeweiligen Elements ermittelt.",
    "The requested dictionary import history entry could not be found.": "Der angeforderte Wörterbuchimport-Verlaufseintrag konnte nicht gefunden werden.",
    "Dictionary undo restore completed.": "Wörterbuch-Wiederherstellung zum Rückgängigmachen abgeschlossen.",
    "Created: %1$d | Updated: %2$d | Deleted: %3$d": "Erstellt: %1$d | Aktualisiert: %2$d | Gelöscht: %3$d",
    "Running": "Läuft",
    "Entry Language": "Eintragssprache",
    "Definition Language": "Definitionssprache",
    "Dictionary Manager": "Wörterbuch-Manager",
    "Back to Dictionary Manager": "Zurück zum Wörterbuch-Manager",
    "regional-dictionary": "regional-dictionary",
    "The selected verb mood override is invalid for this word set.": "Die ausgewählte Verbmodus-Überschreibung ist für dieses Vokabelset ungültig.",
    "Enable verb mood for this word set": "Verbmodus für dieses Vokabelset aktivieren",
    "Images bundle": "Bilder-Bündel",
    "Could not open the export zip for batched writing.": "Die Export-Zip-Datei konnte nicht für das schrittweise Schreiben geöffnet werden.",
    "Recording text": "Aufnahmetext",
    "Download CSV": "CSV herunterladen",
    "Training text field": "Trainingstextfeld",
    "Source word set names": "Namen der Quell-Vokabelsets",
    "CSV issue(s)": "CSV-Problem(e)",
    "Metadata update import failed: the CSV file could not be read.": "Metadaten-Update-Import fehlgeschlagen: Die CSV-Datei konnte nicht gelesen werden.",
    "Metadata update import failed: the CSV header row could not be read.": "Metadaten-Update-Import fehlgeschlagen: Die CSV-Kopfzeile konnte nicht gelesen werden.",
    "Metadata update import failed: the JSON file is not valid JSON.": "Metadaten-Update-Import fehlgeschlagen: Die JSON-Datei enthält kein gültiges JSON.",
    'Word %1$d has multiple recordings of type "%2$s". Use recording_id or recording_slug instead.': 'Vokabel %1$d hat mehrere Aufnahmen des Typs "%2$s". Verwende stattdessen recording_id oder recording_slug.',
    "Row %d: the recording does not belong to the specified word.": "Zeile %d: Die Aufnahme gehört nicht zur angegebenen Vokabel.",
    "Word: %s": "Vokabel: %s",
    "Word set template export is not available right now.": "Der Vokabelset-Vorlagenexport ist derzeit nicht verfügbar.",
    "Parent word title": "Titel der übergeordneten Vokabel",
    "Imported wrong-image preferences were skipped because word option rules are unavailable.": "Importierte Falschbild-Einstellungen wurden übersprungen, weil die Regeln für Antwortoptionen nicht verfügbar sind.",
    "Apply Crops": "Zuschnitte anwenden",
    "%1$d images need fixes out of %2$d tracked images.": "Bei %1$d von %2$d erfassten Bildern sind Korrekturen nötig.",
    "Find mixed-aspect categories, preview fixed-ratio crops, adjust crop boxes, or apply white-padding updates to affected posts.": "Finde Kategorien mit gemischten Seitenverhältnissen, prüfe Zuschnitte mit festem Verhältnis in der Vorschau, passe Zuschnittrahmen an oder wende Weißrand-Aktualisierungen auf betroffene Beiträge an.",
    "Could not save optimized animated WebP image.": "Das optimierte animierte WebP-Bild konnte nicht gespeichert werden.",
    "Optimized image and updated the word image.": "Bild optimiert und Vokabelbild aktualisiert.",
    "Oversized WebP threshold: %1$s (animated WebP: %2$s)": "Schwelle für übergroße WebP-Dateien: %1$s (animiertes WebP: %2$s)",
    "No recordings matched this search.": "Keine Aufnahmen entsprachen dieser Suche.",
    "Next sound(s)": "Nächste(r) Laut(e)",
    "Mark text reviewed": "Text als geprüft markieren",
    "Mark pronunciation reviewed": "Aussprache als geprüft markieren",
    "Word-final": "wortfinal",
    "Apply the current rules to words that have IPA saved but still need written text.": "Wende die aktuellen Regeln auf Vokabeln an, bei denen IPA gespeichert ist, aber noch geschriebener Text fehlt.",
    "Could not stage jQuery for the offline app shell.": "jQuery konnte nicht für die Offline-App-Shell bereitgestellt werden.",
    "Word %d could not bundle its featured image because the source file is missing.": "Vokabel %d konnte ihr Beitragsbild nicht bündeln, weil die Quelldatei fehlt.",
    "Enter the remote word set slug or ID.": "Gib den Remote-Vokabelset-Slug oder die Remote-Vokabelset-ID ein.",
    "The selected local recording is not part of the configured staging word set.": "Die ausgewählte lokale Aufnahme gehört nicht zum konfigurierten Staging-Vokabelset.",
    "Push batches stopped before finishing.": "Push-Batches wurden vor dem Abschluss gestoppt.",
    "Local staging word set": "Lokales Staging-Vokabelset",
    "Remote word set slug or ID": "Remote-Vokabelset-Slug oder -ID",
    "Conflicts": "Konflikte",
    "Count: %d": "Anzahl: %d",
    "Teacher assignment is currently unavailable.": "Die Zuweisung von Lehrkräften ist derzeit nicht verfügbar.",
    "Learner account": "Lernendenkonto",
    "Select a learner account": "Wähle ein Lernendenkonto",
    "Learner account deleted.": "Lernendenkonto gelöscht.",
    "Learner email": "E-Mail-Adresse des Lernenden",
    "Create learner account": "Lernendenkonto erstellen",
    "Learning coverage": "Lernabdeckung",
    "Hard": "Schwierig",
    "Images and any generated words will automatically use this word set.": "Bilder und alle generierten Vokabeln werden automatisch dieses Vokabelset verwenden.",
    "No flag": "Keine Markierung",
    "STT Calls": "STT-Aufrufe",
    "Imported Senses": "Importierte Bedeutungen",
    "Gender/Number": "Genus/Numerus",
    "Recording transcriptions": "Transkriptionen von Aufnahmen",
    "Hosted STT API": "Gehostete STT-API",
    "Changes saved.": "Änderungen gespeichert.",
    "Move Filtered Words": "Gefilterte Vokabeln verschieben",
    "Show words missing published audio": "Vokabeln ohne veröffentlichte Audioaufnahme anzeigen",
    "Sign in to play with your in-progress words.": "Melde dich an, um mit Vokabeln zu spielen, die du gerade lernst.",
    "Say the word for this prompt.": "Sag die Vokabel zu diesem Hinweis.",
    "Speaking Games Access": "Zugriff auf Sprechspiele",
    "Speaking Game STT Provider": "STT-Anbieter für Sprechspiele",
    "Speaking Game Comparison Target": "Vergleichsziel für Sprechspiele",
    "Word set settings were updated.": "Die Vokabelset-Einstellungen wurden aktualisiert.",
    "Audio recorder user": "Audio-Recorder-Benutzer",
    "In progress words": "Angefangene Vokabeln",
    "Unstarred only": "Nur ohne Stern",
    "Last practiced": "Zuletzt geübt",
    "Unscoped Entries": "Einträge ohne Geltungsbereich",
    "All Parts of Speech": "Alle Wortarten",
    "No prompt cards found in Trash.": "Keine Prompt-Karten im Papierkorb gefunden.",
    "Prompt audio": "Hinweis-Audio",
    "Wrong answer word IDs": "Vokabel-IDs für falsche Antworten",
    "Word mastery tracking": "Erfassung der Vokabelbeherrschung",
    "Word Mastery": "Vokabelfortschritt",
    "Tracks answer word": "Verfolgt die Antwortvokabel",
    "Could not load recording types for the selected recordings.": "Die Aufnahmetypen für die ausgewählten Aufnahmen konnten nicht geladen werden.",
    "Enabled when selected recordings all share the same recording types.": "Aktiviert, wenn alle ausgewählten Aufnahmen dieselben Aufnahmetypen haben.",
    "Audio Recording Details": "Details zur Audioaufnahme",
    "Associated Word": "Zugeordnete Vokabel",
    "Enter the Post ID of a word that looks similar:": "Gib die Beitrags-ID einer ähnlich aussehenden Vokabel ein:",
    "Ask the word set manager to assign a private word set.": "Bitte den Vokabelset-Manager darum, ein privates Vokabelset zuzuweisen.",
    "Preparing new word...": "Neue Vokabel wird vorbereitet...",
    "Set:": "Vokabelset:",
    "Target Word (optional)": "Zielvokabel (optional)",
    "recordings completed": "Aufnahmen abgeschlossen",
    "New word recording is not enabled for your account.": "Das Aufnehmen neuer Vokabeln ist für dein Konto nicht aktiviert.",
    "Used for recording summaries and user registration notifications.": "Wird für Aufnahmezusammenfassungen und Benachrichtigungen bei Benutzerregistrierungen verwendet.",
    "Purged %1$d legacy audio meta rows out of %2$d found.": "%1$d von %2$d gefundenen veralteten Audio-Metazeilen gelöscht.",
    "Add a media URL in the lesson editor to play this lesson here.": "Füge im Lektionseditor eine Medien-URL hinzu, um diese Lektion hier abzuspielen.",
    "Replace transcription for this lesson": "Transkription für diese Lektion ersetzen",
    "Replace": "Ersetzen",
    "Noun plurality": "Numerus des Substantivs",
    "Print options": "Druckoptionen",
    "Return to the main audio/video lesson that introduced this vocabulary.": "Kehre zur Hauptlektion mit Audio/Video zurück, in der diese Vokabeln eingeführt wurden.",
    "These recordings use their saved original audio source. Saving replaces the current processed audio for the same word.": "Diese Aufnahmen verwenden ihre gespeicherte Original-Audioquelle. Beim Speichern wird das aktuell bearbeitete Audio für dieselbe Vokabel ersetzt.",
    "Common recording types: Isolation (word alone), Question (asking about the word), Introduction (using in context), Sentence (word in a sentence)": "Übliche Aufnahmetypen: Isolation (Vokabel allein), Frage (Frage nach der Vokabel), Einführung (Verwendung im Kontext), Satz (Vokabel in einem Satz)",
    "Select at least one audio recording to move to the new word.": "Wähle mindestens eine Audioaufnahme aus, um sie zur neuen Vokabel zu verschieben.",
    "Check recordings to move to the new word. Unchecked recordings stay on the original word.": "Markiere Aufnahmen, um sie zur neuen Vokabel zu verschieben. Nicht markierte Aufnahmen bleiben der ursprünglichen Vokabel zugeordnet.",
    "Could not create the synced word.": "Konnte die synchronisierte Vokabel nicht erstellen.",
    "Could not create the synced recording because the local word is missing.": "Konnte die synchronisierte Aufnahme nicht erstellen, weil die lokale Vokabel fehlt.",
    "Administrators can add an existing learner account to this class immediately without waiting for the learner to open an invitation link.": "Administratoren können ein bestehendes Lernendenkonto sofort zu dieser Klasse hinzufügen, ohne darauf zu warten, dass Lernende einen Einladungslink öffnen.",
    "Word set manager invitation sent to %s.": "Einladung für Vokabelset-Manager an %s gesendet.",
    "Word set manager invitation sent.": "Einladung für Vokabelset-Manager gesendet.",
    "The word set manager invitation email could not be sent.": "Die Einladungs-E-Mail für Vokabelset-Manager konnte nicht gesendet werden.",
    "This word set manager invitation link is invalid.": "Dieser Einladungslink für Vokabelset-Manager ist ungültig.",
    "This word set manager invitation link has expired.": "Dieser Einladungslink für Vokabelset-Manager ist abgelaufen.",
    "This word set manager invitation no longer matches an available word set.": "Diese Einladung für Vokabelset-Manager gehört nicht mehr zu einem verfügbaren Vokabelset.",
    "Choose the primary word set this user is allowed to manage. Additional manager assignments can be added from each word set settings page.": "Wähle das primäre Vokabelset aus, das dieser Benutzer verwalten darf. Weitere Manager-Zuweisungen können auf den Einstellungsseiten der jeweiligen Vokabelsets hinzugefügt werden.",
    'Skipped wrong-answer audio "%1$s" on row %2$d in CSV "%3$s" because the file was not found.': 'Antwort-Audio "%1$s" für eine falsche Antwort in Zeile %2$d der CSV "%3$s" wurde übersprungen, weil die Datei nicht gefunden wurde.',
    "Microphone access was not granted. If no browser prompt appears, open Site settings from the lock icon and allow Microphone for this site, then reload.": "Der Zugriff auf das Mikrofon wurde nicht gewährt. Wenn keine Browser-Abfrage erscheint, öffne die Website-Einstellungen über das Schloss-Symbol, erlaube den Mikrofonzugriff für diese Website und lade die Seite neu.",
    "Choose one of the fonts that are already loaded on your site. If you want to add a custom font, add it with the Use Any Font plugin or enqueue it manually.": "Wähle eine der Schriftarten, die bereits auf deiner Website geladen sind. Wenn du eine benutzerdefinierte Schriftart hinzufügen möchtest, füge sie mit dem Plugin Use Any Font hinzu oder binde sie manuell ein.",
    "Import in progress": "Import läuft",
    "Export in progress": "Export läuft",
    "Replace existing structured senses for matching headwords instead of merging.": "Ersetze vorhandene strukturierte Bedeutungen für übereinstimmende Stichwörter, anstatt sie zusammenzuführen.",
}

plurals = {
    "%d audio recording needs processing": (
        "%d Audioaufnahme muss bearbeitet werden",
        "%d Audioaufnahmen müssen bearbeitet werden",
    ),
    "%d conflict needs review": (
        "%d Konflikt muss geprüft werden",
        "%d Konflikte müssen geprüft werden",
    ),
    "Flagged %d word for missing audio review.": (
        "Für %d Vokabel wurde eine Prüfung auf fehlendes Audio markiert.",
        "Für %d Vokabeln wurde eine Prüfung auf fehlendes Audio markiert.",
    ),
    "Undid %d item.": (
        "%d Element rückgängig gemacht.",
        "%d Elemente rückgängig gemacht.",
    ),
    "%d linked word": (
        "%d verknüpfte Vokabel",
        "%d verknüpfte Vokabeln",
    ),
    "%d cue": (
        "%d Transkriptabschnitt",
        "%d Transkriptabschnitte",
    ),
}

contains = {
    "Manage dictionary TSV imports": "Verwalte Wörterbuch-TSV-Importe, Gesamtwebsite-Snapshot-Importe und Exporte an einem Ort. TSV-Zeilen sind nach Stichwörtern gruppiert, sodass Suche, Browsen, Massenübersetzungen und Vokabelverknüpfungen dieselben Daten verwenden.",
    "Whole-site dictionary snapshots preserve": "Gesamtwebsite-Wörterbuch-Snapshots bewahren importierte Wörterbucheinträge, mehrsprachige Definitionen, strukturierte Bedeutungen und Verknüpfungen zu Lernvokabeln.",
    "Override mode updates matching entries": "Der Überschreibungsmodus aktualisiert übereinstimmende Einträge anhand von import_key, behält mit diesen Einträgen verknüpfte Lernvokabeln bei und ersetzt nur importierte Wörterbuchdaten.",
    "Leave both the ID and label blank": "Lass sowohl die ID als auch die Bezeichnung leer, um die Standardquelle zu verwenden.",
    "You do not have permission to access LL Tools import preview media": "Du hast keine Berechtigung, auf LL Tools-Importvorschau-Medien zuzugreifen.",
    "Use recording_id or recording_slug when you need to identify a recording": "Verwende recording_id oder recording_slug, wenn du eine Aufnahme eindeutig identifizieren musst.",
    "CSV parsing skipped": "CSV-Parsing hat %d Datei vor dem Import übersprungen. Lies die Warnungen unten für Details.",
    "top-level CSV files": "CSV-Dateien auf oberster Ebene",
    "The offline bundle exports the standalone APK shell": "Das Offline-Bündel exportiert die eigenständige APK-Shell sowie gebündelte Medien für dieses Vokabelset. Es enthält lokale-first Fortschrittsdaten, damit Lernende Lernstatus und Fortschritt synchronisieren können, wenn sie wieder online sind.",
    "Reverted %d local field": "%d lokale(s) Feld(er) zurückgesetzt. Sieh dir die Vorschau erneut an, bevor du fortfährst.",
    "Pull finished. Updated %1$d local recordings": "Pull beendet. %1$d lokale Aufnahmen aktualisiert, %2$d Vokabeln und %3$d Aufnahmen erstellt, %4$d Aufnahmen verschoben, %5$d lokale Werte akzeptiert.",
    "Review the baseline": "Prüfe den Ausgangsstand, akzeptierte Live-Werte und Konflikte, bevor du saubere Änderungen anwendest.",
    "The selected word is invalid": "Die ausgewählte Vokabel ist ungültig.",
    "Similar image pairs can be removed": "Ähnliche Bildpaare können entfernt werden, um sie manuell zusammen zuzulassen.",
    "Provide at least one word set assignment": "Gib mindestens eine Vokabelset-Zuordnung für die Lernkarte an.",
    "Missing audio review: this word needs audio before it is learner-ready": "Prüfung auf fehlendes Audio: Diese Vokabel braucht Audio, bevor sie für Lernende bereit ist.",
    "Speaking games are hidden because the current speaking configuration is incompatible": "Sprechspiele sind ausgeblendet, weil die aktuelle Sprechkonfiguration nicht kompatibel ist.",
    "Speaking service is enabled but not responding": "Der Sprechdienst ist aktiviert, antwortet aber nicht.",
    "speaking setup is not available": "weil die Sprecheinrichtung dieses Vokabelsets nicht verfügbar ist.",
    "This game shows a picture": "Dieses Spiel zeigt ein Bild und prüft per Spracherkennung, ob Lernende die Vokabel sagen.",
    "manage word set managers": "Vokabelset-Manager verwalten",
    "word set manager invitation link is invalid": "Dieser Einladungslink für Vokabelset-Manager ist ungültig.",
    "Unable to save prompts for that queue item": "Aufnahmehinweise für dieses Warteschlangenelement können nicht gespeichert werden.",
    "Review each assigned recorder's queue": "Überprüfe die Warteschlange jedes zugewiesenen Audio-Recorders nach Kategorie, bearbeite Aufnahmehinweise und passe die Aufnahmeeinstellungen an einem Ort an.",
    "locks their recording interface": "beschränkt seine Aufnahmeoberfläche auf dieses Vokabelset.",
    "add the Audio Recorder role": "weise die Rolle „Audio-Recorder“ zu",
    "delete your progress": "Vokabelfortschritt löschen",
    "No hard words": "Keine schwierigen Vokabeln",
    "If an ID is set above": "Wenn oben eine ID festgelegt ist, hat die ID Vorrang.",
    "No words linked": "Mit diesem Wörterbucheintrag sind noch keine Vokabeln verknüpft.",
    "Use the linked image record": "Verwende den verknüpften Bilddatensatz, um Bilder zu ändern.",
    'ask for the "Audio Recorder" user role': 'Bitte um die Benutzerrolle "Audio-Recorder"',
    "Ask the word set manager to assign": "Bitte den Vokabelset-Manager darum, ein privates Vokabelset zuzuweisen.",
    "none have a featured image set": "bei keinem ist ein Beitragsbild festgelegt.",
    "Flushed quiz caches": "Quiz-Caches geleert. %1$d transiente Zeilen gelöscht, Cache-Versionen für %2$d Kategorien erhöht, Objekt-Cache geleert: %3$s.",
    "audio lives in child recordings": "da Audio jetzt in untergeordneten Aufnahmen gespeichert ist.",
    "follows the word set setting": "folgt der Vokabelset-Einstellung für Nicht-Text-Quizkategorien.",
    "Estimated media size is %1$s": "Die geschätzte Mediengröße liegt bei %1$s und damit über der Schutzeinstellung für große Exporte (%2$s). Der Export kann trotzdem versucht werden, aber bei langsameren Hosts kann eine Zeitüberschreitung auftreten.",
}

literal_replacements = [
    ("Dictionary Manager", "Wörterbuch-Manager"),
    ("strukturierte Sinne", "strukturierte Bedeutungen"),
    ("Eintrag Sprache", "Eintragssprache"),
    ("Definition Sprache", "Definitionssprache"),
    ("Verb mood override", "Verbmodus-Überschreibung"),
    ("Verb-Stimmung", "Verbmodus"),
    ("Verbstimmung", "Verbmodus"),
    ("Vokabel-Einträge", "Wörterbucheinträge"),
    ("Vokabel Mastery", "Vokabelfortschritt"),
    ("Spuren Antwort Vokabel", "Verfolgt die Antwortvokabel"),
    ("Vokabeln: %s", "Vokabel: %s"),
    ("Gewächse", "Zuschnitte"),
    ("Nächste(r) Ton(e)", "Nächste(r) Laut(e)"),
    ("Vokabel-final", "wortfinal"),
    ("Remote Vokabelset slug oder-ID", "Remote-Vokabelset-Slug oder -ID"),
    ("Remote Vokabelset slug oder -ID", "Remote-Vokabelset-Slug oder -ID"),
    ("Widersprüche", "Konflikte"),
    ("Zählen: %d", "Anzahl: %d"),
    ("Lernende Konto", "Lernendenkonto"),
    ("STT Aufrufen", "STT-Aufrufe"),
    ("Gehostet STT API", "Gehostete STT-API"),
    ("Änderungen werden gespeichert.", "Änderungen gespeichert."),
    ("Verschieben Gefilterte Vokabeln", "Gefilterte Vokabeln verschieben"),
    ("sprechende Konfiguration", "Sprechkonfiguration"),
    ("sprechende Dienst", "Sprechdienst"),
    ("Sprechen Spiele Zugang", "Zugriff auf Sprechspiele"),
    ("Speaking Game STT Anbieter", "STT-Anbieter für Sprechspiele"),
    ("Speaking Game Vergleich Ziel", "Vergleichsziel für Sprechspiele"),
    ("Das Vokabelset-Einstellungen", "Die Vokabelset-Einstellungen"),
    ("Vokabelsets-Manager", "Vokabelset-Manager"),
    ("Audioaufnahmen Benutzer", "Audio-Recorder-Benutzer"),
    ("Rolle Vokabel-Audio", "Rolle „Audio-Recorder“"),
    ("Vokabeln im Vokabel-Fortschritt", "Angefangene Vokabeln"),
    ("Vokabeln-Fortschritt", "Vokabelfortschritt"),
    ("harten Vokabeln", "schwierigen Vokabeln"),
    ("Zuletzt praktiziert", "Zuletzt geübt"),
    ("Nicht kopierte Einträge", "Einträge ohne Geltungsbereich"),
    ("Alle Redeteile", "Alle Wortarten"),
    ("Aufforderung Audio", "Hinweis-Audio"),
    ("Falsche Antwort Vokabel IDs", "Vokabel-IDs für falsche Antworten"),
    ("Verfolgung der Vokabel-Beherrschung", "Erfassung der Vokabelbeherrschung"),
    ("Aufnahmetypen für die ausgewählten Aufnahmetypen", "Aufnahmetypen für die ausgewählten Aufnahmen"),
    ("Audioaufnahmen Details", "Details zur Audioaufnahme"),
    ("Assoziiertes Vokabel", "Zugeordnete Vokabel"),
    ("Post-ID eines Vokabels", "Beitrags-ID einer Vokabel"),
    ("Neues Vokabel vorbereiten", "Neue Vokabel wird vorbereitet"),
    ("Ziel Vokabel", "Zielvokabel"),
    ("aufnahmen abgeschlossen", "Aufnahmen abgeschlossen"),
    ("Neues Vokabeln aufnehmen", "Das Aufnehmen neuer Vokabeln"),
    ("Medium-URL", "Medien-URL"),
    ("Druck-Optionen", "Druckoptionen"),
    ("Schlüssel API speichern", "API-Schlüssel speichern"),
    ("Laufende", "Läuft"),
    ("Herunterladen CSV", "CSV herunterladen"),
    ("Textfeld Ausbildung", "Trainingstextfeld"),
    ("Quelle Vokabelsets Namen", "Namen der Quell-Vokabelsets"),
    ("CSV Ausgabe", "CSV-Problem"),
]


def set_entry(msgid: str, value: str) -> None:
    global changed
    hits = [entry for entry in po if not entry.obsolete and entry.msgid == msgid and not entry.msgid_plural]
    if not hits:
        missing.append(msgid)
        return
    for entry in hits:
        if entry.msgstr != value:
            entry.msgstr = value
            changed += 1


for msgid, value in exact.items():
    set_entry(msgid, value)

for msgid, values in plurals.items():
    hits = [entry for entry in po if not entry.obsolete and entry.msgid == msgid and entry.msgid_plural]
    if not hits:
        missing.append(msgid)
        continue
    for entry in hits:
        new = {0: values[0], 1: values[1]}
        if entry.msgstr_plural != new:
            entry.msgstr_plural = new
            changed += 1

for needle, value in contains.items():
    hits = [entry for entry in po if not entry.obsolete and needle in entry.msgid and not entry.msgid_plural]
    if not hits:
        missing.append(f"contains:{needle}")
        continue
    if len(hits) > 1:
        ambiguous.append(needle)
        continue
    entry = hits[0]
    if entry.msgstr != value:
        entry.msgstr = value
        changed += 1

for entry in po:
    if entry.obsolete:
        continue
    vals = entry.msgstr_plural if entry.msgid_plural else {None: entry.msgstr}
    for key, old_value in list(vals.items()):
        new_value = old_value
        for old, new in literal_replacements:
            new_value = new_value.replace(old, new)
        if entry.msgid_plural:
            if new_value != old_value:
                entry.msgstr_plural[key] = new_value
                changed += 1
        elif new_value != old_value:
            entry.msgstr = new_value
            changed += 1

po.metadata["PO-Revision-Date"] = "2026-05-21 00:00+0300"
po.save(str(path))

print(f"changed={changed}")
print(f"missing={len(missing)}")
for item in missing[:120]:
    print("missing", item)
print(f"ambiguous={len(ambiguous)}")
for item in ambiguous[:120]:
    print("ambiguous", item)
