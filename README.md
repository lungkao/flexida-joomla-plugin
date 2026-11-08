# Flexida plugin for Joomla — Web Services for FLEXIcontent

`plg_webservices_flexicontent` is a small Joomla **Web Services** plugin that lets API clients — most notably
the [Flexida](https://github.com/lungkao/flexida) desktop editor — create, read and edit
[FLEXIcontent](https://www.flexicontent.org/) items **with their content type, main and secondary
categories, tags and custom fields**.

Joomla's own article API knows nothing about FLEXIcontent: an article saved through it has no type or secondary
categories and its FLEXIcontent fields are lost. This plugin saves through FLEXIcontent's own item model instead.

> **Status: experimental (0.5.0).** Tested on Joomla 6.2 with FLEXIcontent 5.1 only. See "Compatibility" below.

## Install (once per site)

1. Download `plg_webservices_flexicontent-0.5.0.zip` from the [Releases](../../releases) page.
2. In Joomla go to **System → Install → Extensions** and upload the zip.
3. Go to **System → Plugins**, find **Web Services - FLEXIcontent** and make sure it is **enabled**.
4. A Super User (or a user allowed to edit FLEXIcontent items) needs an **API token**: open the user's profile →
   **Joomla API Token**. The **API Authentication – Web Services** (token) plugin must be enabled.
5. The site must use **HTTPS** — clients refuse to send the token over plain HTTP.

Sites without FLEXIcontent need nothing: install nothing, the client behaves like a plain Joomla editor.

## What it adds to the API

All routes live under `/api/index.php/v1/flexicontent/` and use the normal Joomla token authentication.

| Route | What it does |
|---|---|
| `GET types` | The content types |
| `GET types/{id}/fields` | The type's fields: name, label, field type, options of choice fields, whether several values are allowed |
| `GET items/{id}` | An item: type, categories, tags, text, field values (+ names of attached files) |
| `POST items`, `PATCH items/{id}` | Create / update an item through FLEXIcontent's model |
| `POST images` | Upload a picture (JPEG, PNG, GIF, WebP, ≤ 10 MB) to FLEXIcontent's file manager |
| `POST files` | Upload a document/audio/video/archive (a safe allow-list, ≤ 25 MB; scripts and executables are refused) |

`POST /v1/content/articles` and `PATCH /v1/content/articles/{id}` (Joomla's core article routes) are also served as
FLEXIcontent items, so generic Joomla clients keep working on FLEXIcontent sites.

### Field types understood

Text, textarea, date, e-mail, colour, select / multi-select / radio / checkbox (options are resolved by
FLEXIcontent itself, fixed list or SQL), web link, image (single and gallery; file-manager and folder modes),
file, shared media (YouTube / Vimeo URL). Other field types keep their stored value untouched when an item is
updated; they are simply not editable through the API yet (relation, address, phone, field group, subform …).

An update never drops a field it was not told about.

## Compatibility and limits

- Joomla 6.x + FLEXIcontent 5.1 tested. Joomla 4/5 and FLEXIcontent 3.x/4.x are **not tested**; reports welcome.
- Pictures added to a gallery are appended; existing pictures are not removed or reordered through the API.
- A new item needs a main category (`catid`).

## Licence

GNU General Public License version 2 or later (see `LICENSE`).
