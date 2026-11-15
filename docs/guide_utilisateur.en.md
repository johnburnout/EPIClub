<img src="/assets/img/EPIClub.png" alt="EPIClub logo" width="200" height="200">

# User guide – EPIClub

EPIClub is an equipment management application for sports clubs and associations.

> **Note:** section 1 describes the **French** regulatory framework (French Labour Code and Sports Code), in which EPIClub was designed. Check the rules that apply in your country.

---

## 1 Introduction

### Personal Protective Equipment (PPE) in Vertical Sports: Climbing and Caving

#### 1.1 Regulatory Framework and Dual Responsibility

A climbing or caving supervisor (instructor, guide, teacher) is subject to a dual obligation:

*   **As a worker (French Labour Code)**: their employer (association, club, commercial organisation) must protect them from the risks of their professional activity through technical and organisational measures and, as a last resort, **PPE**.
*   **As a supervisor (French Sports Code)**: they have a **heightened duty of safety** towards the participants they supervise. This means making sure participants use **suitable and compliant protective equipment**.

#### 1.2 A Crucial Distinction: PPE for the Supervisor vs. Equipment for the Participant

This boundary is essential to understand who must wear what and who is responsible for what.

| | **PPE (for the Supervisor / Guide)** | **Protective Equipment for the Participant (student, client)** |
| :--- | :--- | :--- |
| **Purpose** | Protect the **instructor** from the risks specific to their **job** while supervising. | Protect the **climber/caver** from the risks **inherent in practising** the sport. |
| **Obligation** | **The employer's obligation** towards their employee. | **The supervisor's obligation** (and the organiser's) towards the participant. The supervisor must require it and check that it is used correctly. |
| **Examples in Climbing/Caving** | • **Gloves** for handling ropes over long periods (trekking, canyoning descents).<br>• **Helmet** when setting up routes or in unstable terrain (rockfall).<br>• **Safety glasses** when bolting routes (drilling).<br>• **Harness** and **belay device** suited to their professional activity. | • **Helmet** (mandatory).<br>• CE-certified **harness**.<br>• **Belay system** (Grigri, tube, etc.).<br>• UIAA/CE-certified **rope**.<br>• **Quickdraws**, **karabiners**, **lanyards**. |
| **Funding** | **Paid for by the employer** (club, organisation). | Usually **paid for by the participant**, but the supervisor must make sure it is **compliant** and in **good condition**. |

> **Major consequence:** the supervisor not only has the right but **the duty to forbid participation** to anyone not wearing the mandatory protective equipment or using it dangerously.

#### 1.3 Risk Analysis and PPE by Discipline

##### 🧗‍♂️ Climbing (Crag, Climbing Wall, Bouldering)

| Role | Specific Risks | Required Protective Equipment |
| :--- | :--- | :--- |
| **For the Supervisor (PPE)** | • **Falling rocks** or objects during setup.<br>• **Rope burns** (for the person belaying a top rope).<br>• **Cuts** (handling ropes and equipment).<br>• **Prolonged sun exposure**. | • **Helmet** (while equipping, in unstable terrain).<br>• **Rope gloves** (for intensive rope handling, especially in canyoning or for via ferrata belayers).<br>• **Sunglasses**, sun cream.<br>• Sturdy, comfortable **professional harness** for long sessions. |
| **For the Climber** | • **Falls**.<br>• **Impact** with the wall.<br>• **Falling rocks** or equipment. | • **Helmet** (strongly recommended at crags, mandatory in schools and at many venues).<br>• CE-certified **harness**.<br>• A suitable, well-mastered **belay system**.<br>• **Climbing shoes**. |

##### ⛰️ Caving

| Role | Specific Risks | Required Protective Equipment |
| :--- | :--- | :--- |
| **For the Supervisor (PPE)** | • **Falling rocks** (constant risk).<br>• **Intensive rope handling** (burns, cuts).<br>• **Thermal conditions** (cold, humidity).<br>• **Lighting failure**.<br>• **Handling heavy equipment**. | • Caving **helmet with chinstrap** and headlamp **with dual autonomy** (mandatory).<br>• Waterproof, breathable full-body **oversuit**.<br>• Waterproof, reinforced **gloves**.<br>• Specific caving **harness** (comfortable for long periods sitting).<br>• **Lanyards** and **connectors** suited to technical manoeuvres. |
| **For the Caver** | • **All the risks above, heightened by lack of experience.** | • **Full personal equipment identical to the supervisor's** (helmet, oversuit, harness, backup light).<br>• Warm **technical underwear**.<br>• Suitable **boots** (neoprene). |

#### 1.4 Good Practice for Supervisors

1.  **Systematic Checks (Checklist):**
*   **Before the outing:** check the **compliance (CE/UIAA marking)** and the **condition of the equipment** of each participant (rope, harness, karabiners, helmet). Look for wear, cracks and burns.
*   **During the activity:** check the **knots**, how the **harness is put on**, and the **correct use of the belay system**.

2.  **Training and Teaching:**
*   Train participants in the **correct use of their equipment** (e.g. how to check their own harness, how to belay).
*   Insist on the **non-negotiable importance of wearing a helmet** in caving and outdoor climbing.

3.  **Managing Shared Equipment:**
*   Ropes, quickdraws, karabiners and shared rigging equipment must be **rigorously tracked** (logbook) and taken out of service at the slightest doubt.

4.  **Documentation:**
*   Keep records of **training**, **equipment checks** and **equipment provided** (PPE and shared). This makes it possible to show due diligence in the event of an incident.

#### 1.5 Conclusion

In climbing and caving, protection is intrinsically linked to the practice. For the supervisor, it is twofold:
*   **Protecting themselves** with **PPE** suited to the specific risks of their job (rigging, rope handling).
*   **Protecting participants without exception** by being uncompromising about **wearing the required protective equipment** (helmet, harness, etc.) and using it properly. The supervisor's liability is directly engaged here.
### Personal Protective Equipment

## 2. Managing equipment

### How it works
Items are **not entered directly**.
They are generated automatically from validated **purchase invoices**.

---

### 2.1 Create a purchase (invoice)

**Required role:** `ROLE_ADMIN`

1. Go to **Administration → Purchases**.
2. Click **"New purchase"**.
3. Fill in:
- **Supplier** (select an existing supplier or create one)
- **Invoice reference**
- **Invoice date**
- **Document** (PDF, image, etc. – optional)
4. On the same page, add **one or more rows** of equipment:
- **Reference** (e.g. `CHAIR-001`)
- **Description** (e.g. "Office chair")
- **Category** (e.g. "Furniture")
- **Number** (quantity purchased)
- Tick **"Group as a batch"** if the items must be identical (same reference, same code).

---

### 2.2 Validate the purchase

1. Once the invoice is entered, click **"Validate"**.
2. **Automatic action**:
- For each row, the system creates **as many items as the number given** (or **a single item** if the row is grouped as a batch).
- Each item receives:
- A **reference**: the row's reference (suggested on entry from the reference pattern, see §6 Reference, e.g. `26COR001`), then the following numbers for the other units
- A **name** (identical to the description; can be changed later by an administrator)
- A **commissioning date** equal to the invoice date (can be changed only once on the record, never earlier than the invoice date)
- The **category** and the default **status** "Available"
- An empty **photo** (can be added later)

3. The purchase gets the **"Validated"** status and can no longer be changed — **except for the invoice document**, which can be uploaded or replaced from the purchase record.

**Correcting a purchase's reference or date (super administrator)**: on the purchase record, the cog **"Change the reference and date"** shows the correction form (clicking it again cancels without changing anything).
- **Invoice reference**: its availability is checked as you type; to save the change, type the word **`REFERENCE`** in capitals.
- **Purchase date**: to save the change, type the word **`DATE`** in capitals. The purchase's items follow: a **commissioning date** equal to the old invoice date and a calculated **end-of-use date** (purchase + 10 years) are recalculated. Dates **entered manually** are listed to be **confirmed or corrected** — commissioning cannot precede the new invoice date. **Item references do not change**.

---

### 2.3 Import purchases (Excel / CSV)

**Required role:** `ROLE_ADMIN`

1. Go to **Administration → Purchases**, click **"Import (Excel / CSV)"** and send an Excel (`.xlsx`, `.xls`) or CSV file. The **header row is detected automatically** (within the first 10 rows): the rows above it (title, empty rows…) are ignored.
2. The **analysis** screen then appears:
   - for each column of the file, choose the **matching field** (a match is suggested automatically from the header; you can change it or ignore the column);
   - for each **category in the file that is unknown** to the database: keep "Create the category" (the default) by choosing its **name**, its **code** (suggested automatically) and, if needed, the **"Limited lifespan"** box; or **group it** with another category to be created ("Same category as…"); or **link it to an existing category**;
   - the **"Rows to import"** table shows **all the rows** of the file:
     - each row has a **checkbox**: only ticked rows are imported (the header box ticks / unticks all; the import button shows how many rows are ticked);
     - **non-compliant rows** (data incompatible with the column's field, merged cells, missing purchase date for PPE with a limited lifespan…) are highlighted and **unticked**; they are also listed above the table. Correct them in the table and tick them, otherwise they **will be ignored**;
     - **Max stock** and **Current stock** can be edited (see the rules below);
     - **Purchase date** and **End of life** can be edited; ⏳ marks PPE with a limited lifespan;
     - the **Category** column lets you **assign the row to another category** (another category from the file, including one to be created, or an existing category);
   - confirm to start the import.

**Recognised fields:** `reference` (optional — empty ⇒ automatic reference from the pattern, e.g. `25COR005`), `designation` and `categorie` (**required**), `nombre` (max stock), `nombre_actuel` (current stock — a "stock réel" column is recognised), `date_achat`, `date_peremption`, `facture_reference`, `fournisseur`.

**Import rules:**

- **`categorie`**: the category **code** (e.g. `TCI`) is recognised, otherwise its name; if the category is unknown, the analysis screen offers to **link it to an existing category** or to create it;
- **`nombre` > 1** ⇒ the units are grouped into a **batch** (a single item) whose **initial number** is that number (max stock);
- **current stock** of a batch: between 1 and the max stock (otherwise the row is rejected); if only one of the two values is given, the other takes the same value; if neither is, both are 1;
- **dates**: if only the year is given ⇒ 1 January (purchase date) / 31 December (expiry date); empty purchase date ⇒ today's date; empty expiry date ⇒ no limit;
- **PPE with a limited lifespan** (category with "Maximum service life"): the **purchase date is required** (no default to today); an empty end of life is set to the **purchase date + 10 years, on the last day of the month** (e.g. purchase on 15/03/2019 ⇒ 31/03/2029; purchase "2019" ⇒ 01/01/2019 ⇒ 31/01/2029);
- **grouping into purchases**: rows with the same `facture_reference` are grouped into **a single purchase, validated automatically**; without an invoice reference, items with the **same purchase date** are grouped together;
- the purchase created carries the document **"invoice to be uploaded"**, which can later be replaced from the purchase record by the real PDF (which may cover several invoices); an empty supplier becomes "Divers" (miscellaneous);
- an invoice reference **already known is skipped** (never merged);
- the invoice date is used as the **commissioning date** for items that have none.

3. An **import report** lists the purchases created, the skipped groups, the non-compliant rows set aside, the number of unselected rows and any notes (e.g. different suppliers grouped by date, a category code already taken replaced by an automatic code).

---

### 2.4 Edit an item (after validation)

**Required role:** `ROLE_ADMIN` (`ROLE_CONTROLLEUR`: location only)

1. From the **item list** or an item's **record**, click **"Edit"**.
   An **inspector** reaches the same form but can only change the **location** (the other fields are greyed out).
2. You can then:
- Add or change the **photo**: JPEG, PNG, WebP, GIF or AVIF (if the server supports it), resized automatically and turned upright if it was taken with the phone held vertically
- Change the **location**
- Edit the **remarks**
- Fill in the **link to the manufacturer's instructions** (an `http://` or `https://` address of a PDF or web page; leave empty to clear it). The item record then shows a **"View the instructions"** link, which opens in a new tab; the PDF record includes the address
- Update the **dates** (commissioning, end of use)
- Change the **status** (Available, Under maintenance). The **Out of service** status is only set and cleared when an **inspection is closed**: it is not offered on the record, and an out-of-service item keeps this status until a new inspection changes it. The same applies to **On loan** (set when a loan is validated, cleared when the return is checked) and **Lost** (set by an inspection or a loan return, cleared by an inspection) — see "Equipment loans".
- Change the **wear condition** (if defined)
- For a **batch**: adjust the **current number of units**, between 1 and the batch's **initial number** (shown; can only be changed by a super administrator, see below) — for example when units of the batch are lost or withdrawn.

- The **commissioning date** equals the invoice date: it can be changed **only once**, never earlier than the invoice date (a super administrator can change it at any time, still not before the invoice).
- The **name** can be changed by an **administrator** (or a super administrator).
- **Reference, category and batch** (super administrator): these fields are locked when the form opens; click the cog **"Change the reference, category and batch"** to enable them. Clicking again cancels: the original values are restored and none of them change.
- The **batch** and its **initial number** are changed without a confirmation word. Unticking **Batch** sets the initial and current numbers back to 1; ticking **Batch** on a single item makes it a full batch (current number = initial number, adjustable). The current number is always capped at the initial number.
- **Split a batch** (administrator and above): the **"Split the batch"** button (for a super administrator: the cog) shows the **"Split the batch"** card under the form; clicking again hides it without changing anything. Enter the **split** of the remaining units, separated by commas (e.g. `4, 3, 3` for a batch of 10), or click **"Into individual items"** (`1, 1, 1…`). The **first** part stays with the current item (same reference, inspection and loan history); each of the others becomes a **new item** — a batch if it has several units, an individual item otherwise — with the same characteristics (purchase, category, name, status, dates, location, photo, instructions). Each part takes its size as its initial number. A **reference** is assigned to each new item (the next numbers of the pattern for the category and purchase year); only a **super administrator** can change it (its availability is then checked). An administrator changes neither an item's reference nor its category. To confirm, type the word **`DISSOCIER`** in capitals. Not possible during an ongoing inspection, nor for an item that is out of service, on loan or lost. Remember to print the labels of the new items.
- **Merge into a batch** (administrator and above): when other items are **identical** (same name, same category, same purchase), the same button (**"Merge into a batch"** or **"Split or merge"**; for a super administrator: the cog) shows the **"Merge into a batch"** card, which lists them. Tick those to merge into the open item: it keeps its **reference**, dates, location and history; their units are added to its current and initial numbers, and they are **archived** (like a deletion: their history is kept). Values that differ from the open item (location, dates) are flagged in orange. To confirm, type the word **`REGROUPER`** in capitals. Not possible for an item under an ongoing inspection, listed on a loan, out of service, on loan or lost, or with a different status. Remember to remove the labels of the merged items.
- The **reference** can only be changed by a **super administrator**: its availability is checked as you type (a reference already used, including by a deleted item, is refused) and, to save the change, you must type the word **`REFERENCE`** in capitals in the confirmation field that appears. The QR code remains valid, but labels already printed show the old reference: remember to reprint them.
- The **category** can only be changed by a **super administrator**. It is entered as on a purchase row: choose a category from the suggested list, or type a new name (the category is then **created** when saving, as PPE). As soon as you type, a **free reference** in the new category is suggested for the item's **purchase year** (next sequence number, according to the reference pattern) and its availability is checked; a reference typed manually is not overwritten, and the old one can be kept. To save the change, you must type the word **`CATEGORIE`** in capitals (and **`REFERENCE`** if the reference also changes).


**Bulk edit** (`ROLE_ADMIN`): in the item list, tick several items (the header box ticks the whole page), then click **"Edit selection"**. On the next page, enable **"Change"** for each field to change (status, location, dates, maximum lifespan, remarks — appended or replaced —, photo, name, instructions link); fields not enabled stay unchanged. The photo is the same for all the selected items. For the commissioning date, items that cannot receive it (date already changed, later invoice) are skipped and reported. Likewise, the status of out-of-service items is not changed (reported).

**Delete an item** (`ROLE_ADMIN`): only possible if the item was declared **Out of service by its last closed inspection**, or has been **Lost for at least 12 months** (delay set on the **Club** page), and no inspection is in progress. Otherwise the record's "Delete" button stays greyed out (on hover: the date from which a lost item can be deleted).

---

### 2.4 bis Request a change (inspectors and administrators)

A change that your role does not allow is **requested** from the item record, with the **"Request a change"** button:
- tick the changes you want — only those you cannot make yourself are offered (an inspector: status, dates, number of units, remarks, photo or instructions, splitting, merging, name, and everything reserved to super administrators; an administrator: reference, category, batch / initial number, purchase reference or date);
- for each one, a **remark is required** (what must change, and why).

The request opens a **ticket** per required level (administrator, super administrator). The people able to handle it (that role or higher):
- receive an **email** — no new email until they have logged in again since the previous one;
- see the request on their **dashboard**, with a link to the item's edit form.

One of them makes the change then closes the ticket (**"Change made"**), or **refuses** it (reason required). The ticket then disappears from the dashboards, and the requester is notified on theirs until they read the reply. All requests are listed in **Inspections → Change requests**.

### 2.5 View / Export
- **Pagination** (all lists: items, purchases, inspections, logs, loans and loaned equipment, reference data, users, requests): it only appears when there are **more than 10 entries**; you then choose 10, 25, 50 or 100 rows per page. With 10 entries or fewer, everything is shown on a single page.

- **Individual record**: from the list, click **"View"** to see all the information (including the inspection history and the related invoice).
- **Individual PDF**: from the record, click **"PDF"**.
- **List PDF / Excel (photos + QR) / Labels**: from the list, use the **"Export"** drop-down menu.
- **Excel (data) / CSV (data)**: flat export of the filtered items (reference, category, location, status, invoice…), meant for viewing — not for re-import.

---

## 3. Inspections

### Create an inspection
- Reserved for users with the **Inspector** role.
- From an item's record, click **Start an inspection**.

### Scan a QR code
- An item's QR code leads to its record or to an ongoing inspection.
- If an inspection is in progress, the item is added to the inspection rows automatically.
- **Built-in scanning (Inspections menu → "Scan an item")**: the phone's camera opens **within the page** — each scanned item is added to the ongoing inspection without leaving the page (visual and audio confirmation, duplicates flagged), with a "Finish" button leading to the inspection form. ⚠️ Requires the site to be served over **HTTPS** (browser requirement for camera access).

### Replace an item's textile component
- Applies to items marked **"Replaceable textile component"** (category box, e.g. quickdraws, copied to generated items and editable on each record).
1. Set the item to **"Under maintenance"** (item record, or failed inspection).
2. Once the textile has been replaced, inspect the item: the inspection form offers the **"Textile component replaced"** box. Tick it and set the status to **OK**.
3. When the **inspection is closed**, the item becomes available again and its **end-of-use date becomes the inspection date + 10 years, on the last day of the month** (e.g. inspection on 21/10/2026 ⇒ 31/10/2036). The replacement appears in the record's inspection history ("Textile replaced").

### Declare equipment lost
- On the inspection form of an item that cannot be found, choose the **"Lost"** status. When the inspection is closed, the item becomes **Lost**.
- A lost item that is found is inspected normally: an **OK** inspection makes it **Available** again (failed: under maintenance).
- An item **on loan** cannot be added to an inspection until its loan has been returned and checked.

### PPE not checked for too long
- An option an administrator can enable: **Administration → 🏠 Dashboard**, **"Unchecked PPE notification"** switch and a time without inspection **between 3 and 12 months (6 by default)**.
- Once enabled, the dashboard of inspectors and administrators shows the number of PPE items whose **last closed inspection** (or, if never inspected, **commissioning**) is older than that period. "Out of service" and "Lost" items are not counted.
- A click opens the list of these PPE items, from the oldest inspection to the most recent. The notification disappears by itself once they have been inspected.

---

## 3 bis. Equipment loans (optional module)

A module to be enabled by a super administrator: **Administration → Club → "Equipment loan manager"**. It is then available to **inspectors** and administrators (**Loans** menu).

### Life cycle of a loan form
1. **Being entered** — *Loans → New loan form*: the borrower's last name, first name, phone and licence number, the loan date and the due date. Add the equipment:
   - from the loan form (filterable list of available equipment, added one by one or with checkboxes);
   - by **scanning QR codes** ("Scan QR codes" button, HTTPS required);
   - from the **item list** (tick, then "Add selection to loan") or an **item record** ("Add to loan"), choosing among the loans being entered.

   Only equipment that is **available**, not under inspection, has not reached its end-of-use date and is on no other loan can be added. The form can be deleted while it is being entered.
2. **Loan in progress** — "Validate the loan" (phone and licence required, at least one item): the equipment becomes **On loan**. The borrower can **sign on the screen** (with a finger on the phone); otherwise, print the **PDF form** and have it signed. The signature can also be collected later ("Get it signed"). Only the borrower's **phone** can still be changed; no more equipment can be added.
3. **Loan ended** — "End the loan" (return date), then check **each returned item**: *Returned — available*, *Returned — under maintenance* or *Lost*. The choice is final; equipment not yet checked stays "On loan".

### PDF form
Available once the loan is in progress (and once it has ended): borrower, dates, equipment list, return commitment, signature boxes (borrower — with the signature collected on screen, if any — and club).

**Overdue** loans (due date passed) are flagged in red in the list ("Overdue" filter) and by a notification on the inspectors' **dashboard**. An item's loan history appears on its record.

---

## 4. Managing users
- Administrators can create, edit and delete users.
- Available roles: `ROLE_USER`, `ROLE_CONTROLLEUR` (inspector), `ROLE_ADMIN`, `ROLE_SUPER_ADMIN`.
- When an account is **created**, the person receives an **email** telling them: their username, their role and the login link. The email **never contains the password**: give it to them, or they choose one with "Forgot your password?". The club email and SMTP server must be configured; otherwise a message asks you to tell them yourself.
- The **"Resend the welcome email"** button on the user record sends the same email again (for example after an email outage, or if it was not received). It follows the same rights as editing the account.
- The **super administrator account created at installation** is **protected** (🔒 in the list): it can be neither deleted nor demoted, and only its owner can change its email address and password.
- There is always at least one super administrator: the last one can be neither deleted nor demoted.
- The user record shows whether **two-factor authentication** is enabled. If the person has lost their phone or passkey and has no backup codes left, **"Reset (lost device)"** removes their app, passkeys and codes: they log in again with their password only and can enable it again. Reserved for **super administrators**; not possible on someone else's protected account.

---

## 5. Login and password
- You log in with either your **username** or your **email address**, and your password.
- From the login page, click **Forgot your password?**.
- An email with a reset link (valid for 24 hours) is sent.

### Two-factor authentication and passkeys (optional)

Every user, whatever their role, can strengthen their account's security from the **🔐 Security** button (top right). You must first confirm your password (valid for 10 minutes).

- **Authenticator app**: scan the QR code with an app (Google Authenticator, Microsoft Authenticator, Aegis, FreeOTP, 2FAS, Bitwarden…), then enter the 6-digit code shown. From then on, each password login asks for this code.
- **Passkey**: stored on your phone, your computer, your password manager or a security key, and unlocked by fingerprint, face or PIN. It acts as a second factor after the password, or lets you log in **without a password** with the **"🔑 Log in with a passkey"** button on the login page. Passkeys require a site served over HTTPS; they are tied to the site's address (a change of domain means registering them again).
- **Backup codes**: 10 single-use codes are shown **only once** when the first factor is enabled. Write them down or print them: each one replaces the app code or the passkey for one login. You can generate new ones (the old ones stop working).
- After 5 incorrect codes, entry is blocked for a few minutes.
- Lost device and no backup codes left: ask a super administrator to reset your two-factor authentication (user record).

---

## 6. Administration

### Interface language
- **Administration → 🏠 Dashboard → ⚙️ Customise the dashboard**, **"🌐 Interface language"** list: French, English, Spanish or Italian, for **the whole club** (pages, login page, emails, PDFs and exports).
- **Automatic (browser language)**: everyone sees the application in their browser's language, or in French if it is not offered.
- The language chosen in the installation wizard becomes the club's language. The command line and the activity log stay in French.

### Categories
- Define equipment categories (with the option of marking an item as PPE).
- Each category has a **code** (e.g. `COR` for "Cordes" — ropes), suggested automatically from the name (first 3 letters; `CO1`, `CO2`… in case of collision) and editable. This code is used for automatic referencing and is recognised on import.
- The **"Maximum service life"** box means that the items in this category have a lifespan limited by the manufacturer (usually 10 years). It is ticked by default on generated items, and can still be changed item by item on the item record (along with the end-of-use / expiry date). When a **purchase is validated**, items in these categories receive an end-of-use date equal to the **purchase (invoice) date + 10 years, on the last day of the month** (e.g. invoice dated 15/03/2026 ⇒ 31/03/2036), which can then be changed on the record.

### Reference
- **Administration → Reference**: defines the global **reference pattern** for items (default `YYCATNNN`).
- Variables: `YY` or `YYYY` (year), `CAT` (category code), `N…` (sequence number — as many `N` as digits).
- Example: `YYCATNNN` → `26COR001` for the first rope entered in 2026.
- The sequence number **restarts each year**, per category (first rope of 2027 → `27COR001`).
- The automatic reference is assigned when a **purchase is validated** and during an **Excel/CSV import** (when the reference column is empty).

### Locations
- Define physical locations (storage place, shelving, etc.).

### Categories, locations and suppliers: viewing
- Every logged-in user can **view** (read only) categories, locations and suppliers: **📚 Reference data** menu (for administrators: the Administration menu, where they remain editable).
- Each list and each record shows the **number of items** assigned (excluding deleted items); a click shows these items in the item list (for a supplier: the items from its purchases).
- Each list has a **search** (ignoring accents and capitals: name, code or description for categories; name or description for locations; name, email or phone for suppliers), **filters** (with or without items; for categories: PPE, maximum lifespan) and an ascending or descending **sort** (name, code for categories, number of items). "Reset" goes back to the full list.

### Purchases
- Manage equipment purchases with invoices and automatic generation of items.
- **Import (Excel / CSV)**: bulk creation of validated purchases from a file (see §2.3).

### Backup and restore (super administrator)
- **Administration → 💾 Backup / restore**.
- **Download a backup**: a `.zip` archive containing the whole database (items, inspections, purchases, users, settings) and all files (photos, club and category images, QR codes, invoices). Keep it off the server (computer, USB stick, cloud).
- The server configuration (`.env.local.php`) is **not** in the archive. Keep a separate copy: its `SECRET_KEY` is required to restore on a new installation.
- **Restore**: choose the archive then **Check the archive**. Nothing is changed at this stage: EPIClub checks that the archive is complete, intact, signed by an installation using the same `SECRET_KEY`, and compatible (version, encryption key). An archive modified outside EPIClub is refused. Archives created before version 2.7.1 are not signed: they can only be restored from the server (`php bin/restaurer.php --nom=… --accepter-non-signee`). The restore only starts after typing **RESTAURER**.
- Restoring **replaces all current data**. Just before, the current state is backed up automatically ("before restore" archive, the last 3 are kept on the server); if an error occurs during the operation, it is restored automatically.
- A backup made with an older version of EPIClub is upgraded after the restore. A backup from a more recent version is refused: update EPIClub first.
- Archive too large to upload: put it by FTP in EPIClub's `var/backups/` folder; it appears in the list of server backups.

### Activity log (administrators)
- **Administration → 🗒️ Activity log**: logins (successful, failed, logouts), inspections (creation, closing, deletion), loans (creation, validation, end, return, deletion), purchases (creation, changes, rows added, changed or deleted, invoice uploaded, validation with the generated items, correction, deletion, import) and items (splitting and merging batches, change in a batch's number of units, deletion), with the date, the user and the IP address. A failed login is linked to the targeted account; if the username entered matches no account, it is recorded as "identifiant inconnu" (unknown username) — the text entered is never kept, since it could be a password. Log entries are written in French.
- Filters by type, by period and by search (user, action).
- **Export (text)** downloads a `.txt` file of the entries shown by the current filters (all pages).
- **Reset** (super administrator): permanently deletes all entries after typing **REINITIALISER**. Export the log first. The reset itself is recorded in the new log.

---

## Support
If you have a problem, contact your club's administrator.
