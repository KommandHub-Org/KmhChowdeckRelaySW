# 0.1.0

Basis-Release, noch nicht für Live-Shops.

- Einstellungen pro Verkaufskanal: Chowdeck-API-Schlüssel und Webhook-Secret, Abholadresse und Kontakt, Warenart, Liefer-PIN, Kundenbenachrichtigungen, angegebener Bestellwert, Aufschlag, Ersatzpreis, Cache-Dauer für Preise.
- „Verbindung testen“ zeigt das Guthaben Ihres Chowdeck-Relay-Wallets oder die Fehlermeldung von Chowdeck. Ein Testschlüssel (sk_test_…) nutzt die Chowdeck-Sandbox.
- Optionale Adresssuche mit Google Maps: Adressen werden für genauere Preise in Koordinaten umgewandelt (in der Chowdeck-Sandbox nötig); aus oder ohne Schlüssel gehen Adressen als Text an Chowdeck.
- Versandart „Kurierlieferung am selben Tag (Chowdeck)“ wird inaktiv angelegt; sie wird nie gelöscht, beim Deaktivieren nur ausgeschaltet.
