# Release and privacy notes

## Suggested privacy notice: retention of tracking data

The following German passage is a template for the website operator's privacy
notice. It must be reconciled with the actual configuration, purposes, legal
basis, tracked fields, and deletion procedures before publication.

> **Aufbewahrungsdauer von Reichweiten- und Nutzungsdaten**
>
> Die im Rahmen der cookielosen Reichweitenmessung erhobenen Rohdaten werden für
> drei Kalendermonate gespeichert. Nach Ablauf dieser Frist werden vollständig
> abgeschlossene Tage zu Tagesstatistiken verdichtet. Dabei werden insbesondere
> sitzungsbezogene Kennungen, exakte Ereigniszeitpunkte, Referrer,
> Browser-Header und URL-Abfrageparameter aus der Historie entfernt und die
> zugehörigen Rohdatensätze gelöscht.
>
> Technische Sicherungskopien können die zuvor gespeicherten Rohdaten für bis zu
> sieben weitere Tage enthalten. Nach Ablauf dieser Sicherungsfrist werden sie
> im Rahmen des automatisierten Löschverfahrens entfernt.
>
> Die verdichteten Tagesstatistiken werden so lange gespeichert, wie sie für die
> Reichweitenmessung und die langfristige Bewertung unseres Onlineangebots
> erforderlich sind. Die Erforderlichkeit wird mindestens jährlich überprüft.
> Entfällt der Verarbeitungszweck oder sind die Daten hierfür nicht mehr
> erforderlich, werden auch die verdichteten Statistiken gelöscht. Soweit die
> verbleibenden Merkmale im konkreten Einzelfall weiterhin einen Personenbezug
> ermöglichen, werden sie bis zur Löschung weiterhin als personenbezogene Daten
> behandelt.

Operational prerequisites for this wording:

- `retention_months` is set to `3`.
- Automatic compaction and the once-per-minute Statamic scheduler are running
  and monitored.
- `backup_retention_days` is set to `7`, and expired backups are actually
  pruned by subsequent successful compaction runs.
- Query strings are not retained in history.
- The operator has defined responsibility for the annual review and deletion of
  aggregated history. The addon currently provides raw-data compaction, but does
  not automatically impose a maximum age on aggregated history.

This template documents the technical retention concept but is not legal advice.
