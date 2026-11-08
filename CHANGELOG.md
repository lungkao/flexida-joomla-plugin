# Changelog

## 0.5.0 — 2026-11-08 (first public release)
- Routes: types, type fields, items (get/create/update), picture upload, document upload.
- Core article API (`/v1/content/articles`) served as FLEXIcontent items.
- Field types: text, textarea, date, e-mail, colour, choice fields (select, multi-select, radio, checkbox),
  web link, image (single / gallery, file-manager and folder mode), file, shared media (YouTube, Vimeo).
- Partial updates keep every field that is not mentioned; serialized values are preserved.
- Clear 422 error when a new item has no main category.
