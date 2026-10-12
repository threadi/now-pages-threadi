# Shortcodes

Die Ausgabe der Now Pages Einträge kann auch über einen Shortcode erfolgen:

`[threadi_now_pages_digest]`

Über Parameter in dem Shortcode kann die Ausgabe weiter spezifiziert werden. Ohne diese greifen die im Plugin hinterlegten Einstellungen.

## Parameter

### period

- Angabe des zu berücksichtigenden Zeitraums
- mögliche Werte:
  - day
  - hour
  - live
  - month
  - 2month
  - 6month
  - week

Beispiel: `[threadi_now_pages_digest period="day"]`

### format

- Angabe des zu verwendenden Formats
- mögliche Werte:
    - verbatim
    - narrative

Beispiel: `[threadi_now_pages_digest format="verbatim"]`

### show_time

- Legt fest, ob die Zeit des Eintrags angezeigt werden soll
- mögliche Werte:
  - true
  - false

Beispiel: `[threadi_now_pages_digest show_time="true"]`

### delay_hours

- Definiert die Größe des Delays bei der Anzeige
- möglich ist jeder Zahlenwert ab 0
- die Angabe ist in Stunden
- der Wert kann das eingestellte Delay (global oder beim Benutzer) nur erhöhen, nie verringern

Beispiel: `[threadi_now_pages_digest delay_hours="24"]`

### group_by_category

- Legt fest, ob die Einträge nach Kategorien gruppiert werden sollen
- mögliche Werte:
    - true
    - false

Beispiel: `[threadi_now_pages_digest group_by_category="true"]`

### show_source

- Legt fest, ob die Quelle der Einträge angezeigt werden soll
- mögliche Werte:
    - true
    - false

Beispiel: `[threadi_now_pages_digest show_source="true"]`

### link_source

- Legt fest, ob die Quelle der Einträge verlinkt werden soll
- Nur nutzbar, wenn gleichzeitig show_source auf true gesetzt wird
- mögliche Werte:
    - true
    - false

Beispiel: `[threadi_now_pages_digest link_source="true"]`

### category

- Legt die Kategorie fest deren Einträge ausgegeben werden sollen
- mögliche Werte entsprechen den term_id's der im Projekt hinterlegten Kategorien

Beispiel: `[threadi_now_pages_digest category="42"]`
