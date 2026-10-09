# Schemas

`eCH-0217-2-0-0.xsd` (VAT return, eCH-0217 V2.0.0) goes here. Download it from
https://www.ech.ch/fr/ech/ech-0217/2.0.0 → Beilagen → eCH-0217-2-0-0.xsd.

When the file is present, every ESTV XML export is validated against it and refused if invalid
(`App\Domain\Vat\Support\Ech0217Exporter::validate`). The schema imports eCH-0058 and eCH-0108,
so validation needs internet access the first time (or local copies with adjusted schemaLocation).
