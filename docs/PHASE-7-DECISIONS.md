# Phase-seven decisions

1. Evaluate the frozen `05f0829` product; no product fix inside QA.
2. Use separate SQLite, MariaDB and PostgreSQL fixtures; isolate the blocking reproduction in a third PostgreSQL database.
3. Stop this candidate's release assessment after confirmed High compatibility defect P7-D001; interrupted matrix runs do not pass.
4. Preserve historical evidence and record baseline observations separately from complete scenario acceptance.
5. Return the defect to phase six. A fresh candidate and a complete new phase-seven cycle are required.
