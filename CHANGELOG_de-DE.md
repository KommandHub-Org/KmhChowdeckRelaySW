# 0.1.0

Basis-Release, noch nicht für Live-Shops.

- Einstellungen pro Verkaufskanal: Chowdeck-API-Schlüssel und Webhook-Secret, Abholadresse und Kontakt, Warenart, Liefer-PIN, Kundenbenachrichtigungen, angegebener Bestellwert, Aufschlag, Ersatzpreis, Cache-Dauer für Preise.
- „Verbindung testen“ zeigt das Guthaben Ihres Chowdeck-Relay-Wallets oder die Fehlermeldung von Chowdeck. Ein Testschlüssel (sk_test_…) nutzt die Chowdeck-Sandbox.
- Optionale Adresssuche mit Google Maps: Adressen werden für genauere Preise in Koordinaten umgewandelt (in der Chowdeck-Sandbox nötig); aus oder ohne Schlüssel gehen Adressen als Text an Chowdeck.
- Versandart „Kurierlieferung am selben Tag (Chowdeck)“ wird inaktiv angelegt; sie wird nie gelöscht, beim Deaktivieren nur ausgeschaltet.
- Live-Preis von Chowdeck im Checkout (plus optionalem Aufschlag), pro Adresse zwischengespeichert. Kann eine Adresse nicht bepreist werden, erklärt der Checkout den Grund und der Kunde wählt eine andere Versandart; ist Chowdeck nicht erreichbar, gilt ein optionaler Ersatzpreis und Sie erhalten eine Admin-Benachrichtigung.
- Ist Chowdeck nicht erreichbar, fragt der Checkout eine Minute lang nicht erneut an, sodass Kunden nur einmal pro Minute auf eine Zeitüberschreitung warten statt auf jeder Seite.
- Chowdeck-Karte in der Bestellung: berechneter und aktueller Chowdeck-Preis, Status, Fahrer, Liefer-PIN und Sendungsverfolgung, mit „Fahrer anfordern“, „Status aktualisieren“ und „Lieferung stornieren“. Hat sich der Preis seit dem Checkout geändert, bestätigen Sie zuerst den neuen Preis; der Versandpreis des Kunden ändert sich nie.
- Sendungsverfolgung: Der Chowdeck-Tracking-Link wird als Sendungsnummer der Bestellung gespeichert und im Kundenkonto und in E-Mails angezeigt.
- Chowdeck-Webhooks halten die Lieferung und den Lieferstatus der Bestellung aktuell (Fahrer zugewiesen, abgeholt, zugestellt, storniert). Webhooks sind mit Ihrem Webhook-Secret signiert; die Einstellungsseite zeigt die URL für Chowdeck und wann der letzte Webhook ankam.
- Fertige, aktive Flows: E-Mail „Fahrer ist unterwegs“ an den Kunden, Zustell-E-Mail, Warnung an Sie, wenn die Fahreranforderung fehlschlägt. Flow-Builder-Aktion „Chowdeck-Fahrer anfordern“ und Auslöser für jeden Lieferstatus.
- Bestellberechtigungen „Chowdeck-Fahrerlieferungen“ (ansehen, bearbeiten).
