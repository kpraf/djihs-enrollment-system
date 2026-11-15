# Data Privacy Act guide

The DJIHS Enrollment System holds personal information about minors, some of it sensitive: religion, disability, Indigenous Peoples membership and 4Ps status. That puts it under the **Data Privacy Act of 2012 (Republic Act No. 10173)** and the rules of the **National Privacy Commission (NPC)**.

This guide lists what the software does for you and what the school still has to do. It is not legal advice. Have the school's Data Protection Officer review it, and check the NPC's current circulars at privacy.gov.ph.

## What the system does

| Requirement | How the system covers it |
| --- | --- |
| **Tell people why their data is collected** (transparency) | A privacy notice for parents at `/privacy`, linked from the sign-in page and the enrollment form. It can be printed with an acknowledgment slip for signing. |
| **Record that the notice was given** | The New Enrollment form can't be submitted until the encoder confirms the parent, guardian or adult learner received the notice. The time and the encoder's name are saved with the enrollment. |
| **Bind staff to confidentiality** | Every staff member accepts a confidentiality agreement once, at first sign-in, before they can open any learner's data. Acceptance is recorded in the audit log. |
| **Give each person only what their work needs** (proportionality) | Each role sees only its own pages. For example, a subject teacher sees only the learners in their classes, and only administrators can download a full backup. |
| **Keep a record of who did what** (accountability) | The audit log records every change, every time someone opens a learner's record, and every download of personal data: class lists, SF1, backups, the user list and the audit log itself. Use **Activity → Views and downloads only** to see access alone. |
| **Right of access** | **Learner Data Record** on any learner's page prints everything the system holds about that learner, including who changed it and who viewed or downloaded it. |
| **Right to correction** | Staff correct records directly, or advisers request a change that the registrar approves. Every correction is logged. |
| **Security** | Individual accounts, passwords that must be changed at first sign-in, limited sign-in attempts, automatic sign-out after 30 idle minutes, https on Render, and no passwords in backups or logs. |

## What the school must do

1. **Name a Data Protection Officer (DPO).** Put their name, email and phone in the server settings (`PRIVACY_DPO_NAME`, `PRIVACY_DPO_EMAIL`, `PRIVACY_DPO_PHONE`) so they appear on the privacy notice. Until then, administrators see a warning on the dashboard.
2. **Set the retention period.** The notice says records are kept "for as long as DepEd rules on school records require". If the school has a specific schedule, set it with `PRIVACY_RETENTION`.
3. **Give the notice to every family.** Print it from `/privacy`, or show it on a phone, before encoding. Keep signed acknowledgment slips if the school wants a paper record.
4. **Plan for a breach.** Decide who to call if a laptop is lost, a file is shared by mistake or an account is misused. Serious breaches must be reported to the NPC and to affected families quickly. The NPC's rules set a deadline of 72 hours. The audit log helps work out what was exposed.
5. **Manage accounts.** Create one account per person and never share accounts. Deactivate an account on the day someone leaves. Review the account list each school year.
6. **Review access now and then.** For example, once a quarter the DPO filters the audit log to **Views and downloads only** and looks for anything unusual, such as many records opened by one person or downloads late at night.
7. **Look after downloaded files.** Class lists, SF1 files and backups contain personal data. Keep them on school devices and delete them when they're no longer needed. Store backups somewhere locked or encrypted.
8. **Choose where the data lives.** With the Render setup, the data is stored on Render's servers in Singapore, which counts as a transfer outside the Philippines. With the school-computer setup, it stays at the school. Both are allowed, but the DPO should know which one is used and say so in the school's privacy documents.
9. **Check whether registration with the NPC is needed.** Some organisations must register their data processing systems with the NPC. The DPO should check the current rules.
10. **Don't use real data for practice.** Use the demo data (`APP_ENV=local`) for training, never copies of real learner records.
