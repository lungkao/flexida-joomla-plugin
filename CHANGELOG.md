# Changelog

## 0.5.1 — security fixes (found by a source audit)
- Writes no longer exceed the caller's own rights: without `core.edit.state` a new item is saved unpublished and an update keeps its state; categories must exist and allow the caller (`core.create` on the category); the access level must be one the caller can see; the content type must exist.
- The text filter configured for the caller's user groups is applied to the article body (as in the Joomla back end).
- `GET /v1/flexicontent/items/{id}`: `core.edit.own` now only opens the caller's own items; items the caller may not read answer 404, the same as missing ones.
- Types and fields are listed only to users allowed to create or edit content; `core.create` on `com_flexicontent` no longer substitutes for `com_content`.
- Uploaded file and picture names no longer keep inner dots (`a.php.pdf` is stored as `a_php.pdf`).
- Shared-media links are stored in a canonical YouTube/Vimeo form instead of the raw posted text.

## 0.5.0 — 2026-11-08 (first public release)
- Routes: types, type fields, items (get/create/update), picture upload, document upload.
- Core article API (`/v1/content/articles`) served as FLEXIcontent items.
- Field types: text, textarea, date, e-mail, colour, choice fields (select, multi-select, radio, checkbox),
  web link, image (single / gallery, file-manager and folder mode), file, shared media (YouTube, Vimeo).
- Partial updates keep every field that is not mentioned; serialized values are preserved.
- Clear 422 error when a new item has no main category.
