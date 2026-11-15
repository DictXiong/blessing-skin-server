<!--
This file was generated entirely by AI.
It has not been reviewed line by line by a human.
-->

# Search implementation

Lexer / Parser / Applier adapt the MIT-licensed work at
https://github.com/mk990/blessing-skin-server/tree/55597dbd3ff244db893a05c141479ded96dfca32/app/Services/Search .
The project's root LICENSE applies. Local changes retain usingSearchString and
searchStringColumns, add sort / fields / limit / from, comma lists, NOT IN,
boolean terms, date precision, quoted escapes, bounded parsing and portable
literal LIKE matching. Model field names and aliases are allowlisted.

This parser supports the application's User, Player and Report search fields.
None declares searchable relationships; arbitrary relationship grammar and the
old package's configurable manager / visitor API are not implemented.
Unknown columns, invalid dates and resource-limit violations return no rows;
unmatched punctuation remains a non-error search. Tests cover actual HTTP
callers and the report page's default query.
