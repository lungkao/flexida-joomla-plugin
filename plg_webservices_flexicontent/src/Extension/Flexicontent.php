<?php
namespace Flexida\Plugin\WebServices\Flexicontent\Extension;

use Joomla\CMS\Event\Application\BeforeApiRouteEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Table\Table;
use Joomla\Event\SubscriberInterface;

\defined('_JEXEC') or die;

/**
 * Web Services adapter for FLEXIcontent: types, fields, items (get, create, update), pictures, files, and the core article API served as FLEXIcontent items.
 */
final class Flexicontent extends CMSPlugin implements SubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return ['onBeforeApiRoute' => 'onBeforeApiRoute'];
    }

    /**
     * Make FLEXIcontent classes loadable from the API application (JPATH_BASE is /api there, which breaks
     * the registration done by plg_system_flexisystem), then serve our own routes.
     */
    public function onBeforeApiRoute(BeforeApiRouteEvent $event): void
    {
        $fc = JPATH_ADMINISTRATOR . '/components/com_flexicontent';

        if (!is_file($fc . '/defineconstants.php')) {
            return;
        }

        \JLoader::register('FlexicontentModelItem', JPATH_SITE . '/components/com_flexicontent/models/item.php');

        $app    = $this->getApplication();
        $path   = trim($event->getRouter()->getRoutePath(), '/');
        $method = $app->input->getMethod();

        // Writes through the core article API cannot work while FLEXIcontent is installed (its system plugin
        // breaks them), so they are served here, as FLEXIcontent items of the default type, answering in the
        // core article format. This keeps clients that only know the core API (stock Grafida, scripts) working.
        $core = [];

        if (preg_match('#^v1/content/articles(?:/(\d+))?$#', $path, $core)) {
            $coreId = isset($core[1]) ? (int) $core[1] : 0;

            if (($method === 'POST' && $coreId === 0) || ($method === 'PATCH' && $coreId > 0)) {
                $this->authenticate();
                $this->saveItem($coreId, true);
            }

            return;
        }

        if (!str_starts_with($path, 'v1/flexicontent/')) {
            return;
        }

        $this->authenticate();
        $m = [];

        if ($path === 'v1/flexicontent/types' && $method === 'GET') {
            $this->listTypes();
        } elseif (preg_match('#^v1/flexicontent/types/(\d+)/fields$#', $path, $m) && $method === 'GET') {
            $this->listFields((int) $m[1]);
        } elseif ($path === 'v1/flexicontent/images' && $method === 'POST') {
            $this->uploadImage();
        } elseif ($path === 'v1/flexicontent/files' && $method === 'POST') {
            $this->uploadDocument();
        } elseif ($path === 'v1/flexicontent/items' && $method === 'POST') {
            $this->saveItem(0);
        } elseif (preg_match('#^v1/flexicontent/items/(\d+)$#', $path, $m)) {
            if ($method === 'GET') {
                $this->getItem((int) $m[1]);
            } elseif ($method === 'PATCH') {
                $this->saveItem((int) $m[1]);
            }
        }

        $this->send(404, ['errors' => [['title' => 'Unknown FLEXIcontent route or method', 'code' => 404]]]);
    }

    /** Authentication normally happens after routing, so do the same silent API login here. */
    private function authenticate(): void
    {
        $app = $this->getApplication();

        if (!$app->login(['username' => ''], ['silent' => true, 'action' => 'core.login.api']) || $app->getIdentity()->guest) {
            $this->send(401, ['errors' => [['title' => 'Authentication required', 'code' => 401]]]);
        }
    }

    private function listTypes(): void
    {
        $db   = Factory::getDbo();
        $rows = $db->setQuery(
            $db->getQuery(true)->select('id, name, alias')->from('#__flexicontent_types')->where('published = 1')->order('id')
        )->loadAssocList();

        $this->send(200, ['data' => array_map(static fn ($r) => ['type' => 'flexicontent-types', 'id' => (string) $r['id'], 'attributes' => $r], $rows)]);
    }

    /** FLEXIcontent field types whose value is one (or several) of a list of allowed values. */
    private const CHOICE_FIELDS = ['select', 'selectmultiple', 'radio', 'radioimage', 'checkbox', 'checkboximage'];

    /**
     * The options of a choice field, resolved by FLEXIcontent's own helper so fixed lists and SQL-driven lists both work.
     *
     * @param array<string, mixed> $attribs
     *
     * @return list<array{value: string, label: string}>
     */
    private function choiceOptions(int $fieldId, string $label, array $attribs): array
    {
        try {
            $this->loadFlexicontent();

            $field             = new \stdClass();
            $field->id         = $fieldId;
            $field->label      = $label;
            $field->parameters = new \Joomla\Registry\Registry($attribs);
            $item              = new \stdClass();
            $item->id          = 0;
            $props             = ['image', 'valgrp', 'state'];
            $pros              = true;

            // The helper reports a malformed list by echoing; keep that out of the JSON answer.
            ob_start();
            $elements = \FlexicontentFields::indexedField_getElements($field, $item, $props, $pros);
            ob_end_clean();
        } catch (\Throwable $e) {
            while (ob_get_level() > 0 && ob_get_length() !== false && ob_get_clean() !== false) {
                break;
            }

            return [];
        }

        $out = [];

        foreach (\is_array($elements) ? $elements : [] as $value => $element) {
            $out[] = ['value' => (string) $value, 'label' => (string) ($element->text ?? $value)];
        }

        return $out;
    }

    private function listFields(int $typeId): void
    {
        $db   = Factory::getDbo();
        $rows = $db->setQuery(
            $db->getQuery(true)
                ->select('f.id, f.name, f.label, f.field_type, f.attribs, f.description')
                ->from('#__flexicontent_fields AS f')
                ->join('INNER', '#__flexicontent_fields_type_relations AS r ON r.field_id = f.id')
                ->where('r.type_id = ' . $typeId)
                ->where('f.published = 1')
                ->where('f.iscore = 0')
                ->order('r.ordering')
        )->loadAssocList();

        $data = [];

        foreach ($rows as $r) {
            $attribs = json_decode((string) $r['attribs'], true) ?: [];
            unset($r['attribs']);
            $r['required'] = !empty($attribs['required']);
            // An image field that holds several pictures (a gallery); a choice field that takes several values.
            $r['multiple'] = (\in_array($r['field_type'], ['image', 'file', 'mediafile'], true) && !empty($attribs['allow_multiple']))
                || \in_array($r['field_type'], ['selectmultiple', 'checkbox', 'checkboximage'], true);

            // The allowed values of a choice field, as FLEXIcontent itself resolves them (a fixed list or a query).
            if (\in_array($r['field_type'], self::CHOICE_FIELDS, true)) {
                $r['options'] = $this->choiceOptions((int) $r['id'], (string) $r['label'], $attribs);
            }
            $data[]        = ['type' => 'flexicontent-fields', 'id' => (string) $r['id'], 'attributes' => $r];
        }

        $this->send(200, ['data' => $data]);
    }

    private function getItem(int $id): void
    {
        $db = Factory::getDbo();
        $c  = $db->setQuery(
            $db->getQuery(true)
                ->select('c.id, c.title, c.alias, c.introtext, c.fulltext, c.state, c.catid, c.language, c.access, e.type_id')
                ->from('#__content AS c')
                ->join('LEFT', '#__flexicontent_items_ext AS e ON e.item_id = c.id')
                ->where('c.id = ' . $id)
        )->loadAssoc();

        if (!$c) {
            $this->send(404, ['errors' => [['title' => 'Item not found', 'code' => 404]]]);
        }

        if (!$this->getApplication()->getIdentity()->authorise('core.edit', 'com_content.article.' . $id)
            && !$this->getApplication()->getIdentity()->authorise('core.edit.own', 'com_content.article.' . $id)) {
            $this->send(403, ['errors' => [['title' => 'Not allowed', 'code' => 403]]]);
        }

        $c['cats'] = array_map('intval', $db->setQuery(
            $db->getQuery(true)->select('catid')->from('#__flexicontent_cats_item_relations')->where('itemid = ' . $id)->order('ordering')
        )->loadColumn());

        $c['tags'] = $db->setQuery(
            $db->getQuery(true)->select('t.name')->from('#__flexicontent_tags_item_relations AS r')
                ->join('INNER', '#__flexicontent_tags AS t ON t.id = r.tid')->where('r.itemid = ' . $id)
        )->loadColumn();

        $vals   = $db->setQuery(
            $db->getQuery(true)->select('f.name, f.field_type, v.value')->from('#__flexicontent_fields_item_relations AS v')
                ->join('INNER', '#__flexicontent_fields AS f ON f.id = v.field_id')
                ->where('v.item_id = ' . $id)->where('f.iscore = 0')->order('f.id, v.valueorder')
        )->loadAssocList();
        $fields = [];

        foreach ($vals as $v) {
            $value = $v['value'];

            // A shared-media value is a serialized array; the editor only deals with its URL.
            if ($v['field_type'] === 'sharedmedia') {
                $decoded = @unserialize($value, ['allowed_classes' => false]);
                $value   = \is_array($decoded) && isset($decoded['url']) ? (string) $decoded['url'] : '';
            }

            // A web link is stored as an array; the editor deals with its address.
            if ($v['field_type'] === 'weblink') {
                $decoded = @unserialize($value, ['allowed_classes' => false]);
                $value   = \is_array($decoded) ? (string) ($decoded['link'] ?? '') : (string) $value;
            }

            // A picture is reported by its file name, so a client can tell what is already there.
            if ($v['field_type'] === 'image') {
                $decoded = @unserialize($value, ['allowed_classes' => false]);
                $value   = \is_array($decoded) ? (string) ($decoded['originalname'] ?: ($decoded['existingname'] ?? '')) : '';
            }

            $fields[$v['name']][] = $value;
        }

        $c['fields'] = (object) $fields;

        // File fields hold file-manager ids; tell the client what those files are called.
        $fileIds = [];

        foreach ($vals as $v) {
            if (\in_array($v['field_type'], ['file', 'mediafile'], true) && ctype_digit((string) $v['value'])) {
                $fileIds[] = (int) $v['value'];
            }
        }

        $c['files'] = (object) [];

        if ($fileIds) {
            $rows = $db->setQuery($db->getQuery(true)->select('id, filename, altname, ext')->from('#__flexicontent_files')->where('id IN (' . implode(',', array_unique($fileIds)) . ')'))->loadAssocList();
            $map  = [];

            foreach ($rows as $row) {
                $map[(string) $row['id']] = ['name' => (string) ($row['altname'] ?: $row['filename']), 'ext' => (string) $row['ext']];
            }

            $c['files'] = (object) $map;
        }

        $this->send(200, ['data' => ['type' => 'flexicontent-items', 'id' => (string) $id, 'attributes' => $c]]);
    }

    /**
     * Copies uploaded pictures from the file manager's folder into the folder FLEXIcontent keeps for an item's
     * image field (`<dir>/item_<id>_field_<field id>/original/`), the way its own form does on upload.
     *
     * @param array<string, list<string>>              $names  Picture file names per field name.
     * @param array<string, array{id: int, dir: string}> $fields Folder-mode image fields.
     */
    private function placeFolderPictures(int $itemId, array $names, array $fields): void
    {
        foreach ($names as $field => $files) {
            if (!isset($fields[$field])) {
                continue;
            }

            $target = JPATH_ROOT . '/' . $fields[$field]['dir'] . '/item_' . $itemId . '_field_' . $fields[$field]['id'] . '/original';

            if (!is_dir($target) && !@mkdir($target, 0755, true) && !is_dir($target)) {
                $this->send(500, ['errors' => [['title' => 'Cannot create the picture folder for field ' . $field, 'code' => 500]]]);
            }

            foreach ($files as $name) {
                $name = basename((string) $name);
                $from = COM_FLEXICONTENT_FILEPATH . DIRECTORY_SEPARATOR . $name;

                if ($name !== '' && is_file($from) && !is_file($target . '/' . $name)) {
                    @copy($from, $target . '/' . $name);
                }
            }
        }
    }

    /**
     * The ids of the files a file field is to hold (the whole list: a file left out is removed from the field, the
     * file itself stays in the file manager). Each must be a file the file manager knows.
     *
     * @return list<string>
     */
    private function fileValues(string $name, mixed $value): array
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn ($v): string => trim((string) $v), \is_array($value) ? $value : [$value]), static fn (string $v): bool => $v !== '')));

        foreach ($ids as $id) {
            if (!ctype_digit($id)) {
                $this->send(422, ['errors' => [['title' => 'Field ' . $name . ' takes file ids (from POST /v1/flexicontent/files)', 'code' => 422]]]);
            }
        }

        if ($ids) {
            $db    = Factory::getDbo();
            $found = $db->setQuery($db->getQuery(true)->select('id')->from('#__flexicontent_files')->where('id IN (' . implode(',', array_map('intval', $ids)) . ')'))->loadColumn();

            if (\count($found) !== \count($ids)) {
                $this->send(422, ['errors' => [['title' => 'Field ' . $name . ': a file id is unknown to the file manager', 'code' => 422]]]);
            }
        }

        return $ids;
    }

    /**
     * POST /v1/flexicontent/files  {"filename": "report.pdf", "content": "<base64>"} -> {"data": {"id": <file id>, "attributes": {...}}}.
     * Stores a document in FLEXIcontent's file manager, as an upload from the item form would. The type is checked
     * against a list of document, audio, video and archive formats (never scripts or executables) and the extension
     * follows the real content, up to 25 MB.
     */
    private function uploadDocument(): void
    {
        $app  = $this->getApplication();
        $user = $app->getIdentity();
        $body = json_decode((string) file_get_contents('php://input'), true);

        if (!$user->authorise('core.create', 'com_content') && !$user->authorise('core.create', 'com_flexicontent') && !$user->authorise('core.edit', 'com_content')) {
            $this->send(403, ['errors' => [['title' => 'Not allowed to upload', 'code' => 403]]]);
        }

        if (!\is_array($body) || !isset($body['filename'], $body['content']) || !\is_string($body['filename']) || !\is_string($body['content'])) {
            $this->send(400, ['errors' => [['title' => 'Body must be {"filename": ..., "content": <base64>}', 'code' => 400]]]);
        }

        $bytes = base64_decode($body['content'], true);

        if ($bytes === false || $bytes === '' || \strlen($bytes) > 25 * 1024 * 1024) {
            $this->send(422, ['errors' => [['title' => 'Content must be base64 and at most 25 MB', 'code' => 422]]]);
        }

        // Extensions that may be stored, with a check that the content is not a script/executable in disguise.
        $allowed = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'rtf', 'txt', 'csv', 'epub', 'zip', 'gz', '7z', 'mp3', 'wav', 'm4a', 'ogg', 'mp4', 'mov', 'webm', 'jpg', 'jpeg', 'png', 'gif', 'webp'];
        $ext     = strtolower(pathinfo($body['filename'], PATHINFO_EXTENSION));
        $mime    = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        if (!\in_array($ext, $allowed, true) || preg_match('#^(text/(x-)?(php|html|x-shellscript|javascript)|application/(x-)?(php|httpd-php|x-executable|x-mach-binary|x-dosexec|javascript))#i', (string) $mime)
            || preg_match('/<\?php|<script\b/i', substr($bytes, 0, 2048)) && !\in_array($ext, ['txt', 'csv'], true)) {
            $this->send(422, ['errors' => [['title' => 'This type of file is not accepted', 'code' => 422]]]);
        }

        $this->ensureShims();
        $this->loadFlexicontent();

        $base = pathinfo(\Joomla\Filesystem\File::makeSafe($body['filename']), PATHINFO_FILENAME) ?: 'file';
        $dir  = COM_FLEXICONTENT_FILEPATH . DIRECTORY_SEPARATOR;

        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            $this->send(500, ['errors' => [['title' => 'Cannot create the upload folder', 'code' => 500]]]);
        }

        $filename = \flexicontent_upload::sanitize($dir, $base . '.' . $ext);

        if (@file_put_contents($dir . $filename, $bytes) === false) {
            $this->send(500, ['errors' => [['title' => 'Cannot write the file', 'code' => 500]]]);
        }

        $db  = Factory::getDbo();
        $row = (object) [
            'filename'    => $filename,
            'altname'     => (string) $body['filename'],
            'url'         => 0,
            'secure'      => 1,
            'access'      => 1,
            'ext'         => $ext,
            'hits'        => 0,
            'size'        => \strlen($bytes),
            'uploaded'    => Factory::getDate()->toSql(),
            'uploaded_by' => (int) $user->id,
        ];
        $db->insertObject('#__flexicontent_files', $row);
        $id = (int) $db->insertid();

        $this->send(201, ['data' => ['type' => 'flexicontent-files', 'id' => (string) $id, 'attributes' => ['id' => $id, 'file' => $filename, 'name' => (string) $body['filename']]]]);
    }

    /**
     * Turn a posted address into the array FLEXIcontent's web-link field stores; an empty value clears the field.
     *
     * @return list<array<string, string>>
     */
    private function webLinkValues(string $name, mixed $value): array
    {
        $urls = array_values(array_filter(array_map(static fn ($v): string => trim((string) $v), \is_array($value) ? $value : [$value]), static fn (string $v): bool => $v !== ''));
        $out  = [];

        foreach ($urls as $url) {
            if (!preg_match('#^(https?://|/|mailto:)#i', $url)) {
                $this->send(422, ['errors' => [['title' => 'Field ' . $name . ': "' . $url . '" is not a web address', 'code' => 422]]]);
            }

            $out[] = ['link' => $url, 'title' => '', 'linktext' => '', 'class' => '', 'id' => '', 'target' => '', 'hits' => 0];
        }

        return $out;
    }

    /**
     * Turn a posted video URL into the array FLEXIcontent's shared-media field stores.
     * Only YouTube and Vimeo links are understood; an empty value clears the field.
     *
     * @return list<array<string, string>>
     */
    private function sharedMediaValues(string $name, mixed $value): array
    {
        $url = trim(\is_array($value) ? (string) ($value[0] ?? '') : (string) $value);

        if ($url === '') {
            return [];
        }

        if (preg_match('#^https?://(?:www\.|m\.)?(?:youtube\.com/(?:watch\?(?:[^\#]*&)?v=|embed/|shorts/)|youtu\.be/)([A-Za-z0-9_-]{6,15})#i', $url, $m)) {
            $id = $m[1];

            return [[
                'url' => $url, 'api_type' => 'youtube', 'media_id' => $id, 'embed_url' => '//www.youtube.com/embed/' . $id,
                'thumb' => 'https://i.ytimg.com/vi/' . $id . '/mqdefault.jpg', 'title' => '', 'author' => '', 'duration' => '',
                'description' => '', 'height' => '0', 'width' => '0',
            ]];
        }

        if (preg_match('#^https?://(?:www\.)?vimeo\.com/(\d{5,12})#i', $url, $m)) {
            return [[
                'url' => $url, 'api_type' => 'vimeo', 'media_id' => $m[1], 'embed_url' => '//player.vimeo.com/video/' . $m[1],
                'thumb' => '', 'title' => '', 'author' => '', 'duration' => '', 'description' => '', 'height' => '0', 'width' => '0',
            ]];
        }

        $this->send(422, ['errors' => [['title' => 'Field ' . $name . ': only YouTube or Vimeo links are supported', 'code' => 422]]]);
    }

    /** Create ($id = 0) or update an item through FLEXIcontent's own model. */
    private function saveItem(int $id, bool $asArticle = false): void
    {
        $app  = $this->getApplication();
        $user = $app->getIdentity();
        $db   = Factory::getDbo();

        $body = json_decode((string) file_get_contents('php://input'), true);

        if (!\is_array($body)) {
            $this->send(400, ['errors' => [['title' => 'Invalid JSON body', 'code' => 400]]]);
        }

        if ($asArticle) {
            $body = $this->fromCoreArticle($body, $id === 0);
        }

        if ($id === 0 && !$user->authorise('core.create', 'com_content') && !$user->authorise('core.create', 'com_flexicontent')) {
            $this->send(403, ['errors' => [['title' => 'Not allowed to create items', 'code' => 403]]]);
        }

        $existing = null;

        if ($id > 0) {
            $existing = $db->setQuery($db->getQuery(true)->select('id, catid, created_by')->from('#__content')->where('id = ' . $id))->loadAssoc();

            if (!$existing) {
                $this->send(404, ['errors' => [['title' => 'Item not found', 'code' => 404]]]);
            }

            $asset = 'com_content.article.' . $id;

            if (!$user->authorise('core.edit', $asset) && !($user->authorise('core.edit.own', $asset) && (int) $existing['created_by'] === (int) $user->id)) {
                $this->send(403, ['errors' => [['title' => 'Not allowed to edit this item', 'code' => 403]]]);
            }
        }

        $this->ensureShims();
        $this->loadFlexicontent();

        // Categories created outside FLEXIcontent are invisible to it until its category cache is cleared.
        Factory::getCache('com_flexicontent_cats')->clean();

        $data = ['id' => $id, 'vstate' => 2];

        $has = static fn (string ...$keys): bool => (bool) array_filter($keys, static fn ($k) => \array_key_exists($k, $body));

        if ($has('introtext', 'articletext', 'fulltext')) {
            $text = (string) ($body['introtext'] ?? $body['articletext'] ?? '');

            if (!empty($body['fulltext'])) {
                $text .= '<hr id="system-readmore" />' . $body['fulltext'];
            }

            $data['text'] = $text;
        }

        foreach (['title', 'alias', 'language', 'metadesc', 'metakey'] as $k) {
            if (\array_key_exists($k, $body)) {
                $data[$k] = (string) $body[$k];
            }
        }

        foreach (['state', 'access', 'catid', 'type_id'] as $k) {
            if (\array_key_exists($k, $body)) {
                $data[$k] = (int) $body[$k];
            }
        }

        if ($id === 0) {
            $data += ['state' => 1, 'access' => 1, 'language' => '*'];

            if (empty($data['type_id'])) {
                $this->send(422, ['errors' => [['title' => 'type_id is required', 'code' => 422]]]);
            }

            // FLEXIcontent files every item under a main category; without one its own checks fail obscurely.
            if (empty($data['catid'])) {
                $this->send(422, ['errors' => [['title' => 'A main category is required for this content type: choose a Category', 'code' => 422]]]);
            }
        } else {
            // An update keeps the main category unless a real one is sent (a null/0 means "not chosen").
            $data['catid'] = !empty($data['catid']) ? $data['catid'] : (int) $existing['catid'];
        }

        if (\array_key_exists('cats', $body)) {
            $data['cid'] = array_values(array_unique(array_map('intval', (array) $body['cats'])));
        }

        if (\array_key_exists('tags', $body)) {
            $data['tags'] = $this->resolveTags((array) $body['tags']);
        }

        // FLEXIcontent's store() treats a field missing from `custom` as emptied, so an update that does not
        // mention a field must send its current values back or it would be wiped.
        $custom       = [];
        $folderFields = []; // image fields in folder mode: name => [id, dir]
        $folderNew    = []; // names of the pictures this request adds to those, per field

        foreach ($db->setQuery($db->getQuery(true)->select('id, name, attribs')->from('#__flexicontent_fields')->where('field_type = ' . $db->quote('image')))->loadAssocList() as $f) {
            $a = json_decode((string) $f['attribs'], true) ?: [];

            // Folder mode (image_source >= 1): the pictures live in the item's own folder instead of the file manager.
            if ((int) ($a['image_source'] ?? 0) >= 1) {
                $folderFields[$f['name']] = ['id' => (int) $f['id'], 'dir' => trim((string) ($a['dir'] ?? 'images/stories/flexicontent'), '/\\')];
            }
        }

        if ($id > 0) {
            $rows = $db->setQuery(
                $db->getQuery(true)->select('f.name, v.value')->from('#__flexicontent_fields_item_relations AS v')
                    ->join('INNER', '#__flexicontent_fields AS f ON f.id = v.field_id')
                    ->where('v.item_id = ' . $id)->where('f.iscore = 0')->order('f.id, v.valueorder, v.suborder')
            )->loadAssocList();

            foreach ($rows as $row) {
                $value = $row['value'];

                // Image/sharedmedia/etc. values are stored as serialized arrays; store() serializes arrays
                // itself, so hand it the array back or the value ends up serialized twice.
                if (\is_string($value) && preg_match('/^a:\d+:\{/', $value)) {
                    $decoded = @unserialize($value, ['allowed_classes' => false]);

                    if (\is_array($decoded)) {
                        $value = $decoded;
                    }
                }

                $custom[$row['name']][] = $value;
            }
        }

        if (!empty($body['fields']) && \is_array($body['fields'])) {
            $fileFields  = $db->setQuery($db->getQuery(true)->select('name')->from('#__flexicontent_fields')->where('field_type IN (' . $db->quote('file') . ',' . $db->quote('mediafile') . ')'))->loadColumn();
            $linkFields  = $db->setQuery($db->getQuery(true)->select('name')->from('#__flexicontent_fields')->where('field_type = ' . $db->quote('weblink')))->loadColumn();
            $mediaFields = $db->setQuery($db->getQuery(true)->select('name')->from('#__flexicontent_fields')->where('field_type = ' . $db->quote('sharedmedia')))->loadColumn();
            $imageFields = $db->setQuery($db->getQuery(true)->select('name')->from('#__flexicontent_fields')->where('field_type = ' . $db->quote('image')))->loadColumn();
            $galleryFields = [];

            foreach ($db->setQuery($db->getQuery(true)->select('id, name, attribs')->from('#__flexicontent_fields')->where('field_type = ' . $db->quote('image')))->loadAssocList() as $f) {
                $a = json_decode((string) $f['attribs'], true) ?: [];

                if (!empty($a['allow_multiple'])) {
                    $galleryFields[] = $f['name'];
                }

            }

            foreach ($body['fields'] as $name => $value) {
                if (\in_array((string) $name, $imageFields, true)) {
                    $new = $this->imageValues($name, $value);

                    if (isset($folderFields[(string) $name])) {
                        // The file sits in the file manager's folder; the field wants it in the item's own folder and
                        // names it by `originalname` alone.
                        $folderNew[(string) $name] = array_column($new, 'existingname');
                        $new = array_map(static fn (array $v): array => ['originalname' => $v['existingname'], 'existingname' => ''] + $v, $new);
                    }

                    // A gallery only ever gains pictures here: the new ones go after those already stored, so
                    // adding to an item never drops what it has.
                    $custom[(string) $name] = \in_array((string) $name, $galleryFields, true) && !empty($custom[(string) $name])
                        ? array_merge($custom[(string) $name], $new)
                        : $new;
                    continue;
                }

                if (\in_array((string) $name, $fileFields, true)) {
                    $custom[(string) $name] = $this->fileValues($name, $value);
                    continue;
                }

                if (\in_array((string) $name, $linkFields, true)) {
                    $custom[(string) $name] = $this->webLinkValues($name, $value);
                    continue;
                }

                if (\in_array((string) $name, $mediaFields, true)) {
                    $custom[(string) $name] = $this->sharedMediaValues($name, $value);
                    continue;
                }

                $custom[(string) $name] = \is_array($value) ? array_values($value) : [$value];
            }
        }

        if ($custom) {
            $data['custom'] = $custom;
        }

        // A folder-mode picture has to be in place before FLEXIcontent stores the field (it makes the thumbnails
        // from it). An existing item has its folder already; a new item gets its id only from the first store, so
        // the pictures are held back, the item stored, the pictures put in its folder, and the item stored again.
        $deferred = [];

        if ($id > 0 && $folderFields) {
            // Every picture the item names, not only the new ones: an earlier save may have left a name whose file
            // never reached the item's folder (it is still in the file manager's), and FLEXIcontent would skip it.
            $present = $folderNew;

            foreach ($folderFields as $name => $info) {
                foreach ((array) ($custom[$name] ?? []) as $v) {
                    $file = \is_array($v) ? (string) (($v['originalname'] ?? '') ?: ($v['existingname'] ?? '')) : '';

                    if ($file !== '') {
                        $present[$name][] = $file;
                    }
                }
            }

            $this->placeFolderPictures($id, $present, $folderFields);
        } elseif ($folderNew) {
            foreach (array_keys($folderNew) as $name) {
                $deferred[$name] = $custom[$name];
                unset($custom[$name]);
            }

            if ($custom) {
                $data['custom'] = $custom;
            } else {
                unset($data['custom']);
            }
        }

        try {
            $model = new \FlexicontentModelItem();
            $ok    = $model->store($data);

            if ($ok && $deferred) {
                $newId = (int) $model->getId();
                $this->placeFolderPictures($newId, $folderNew, $folderFields);

                $data['id']     = $newId;
                $data['custom'] = $deferred + $custom;
                $model          = new \FlexicontentModelItem();
                $ok             = $model->store($data);
            }
        } catch (\Throwable $e) {
            $this->send(500, ['errors' => [['title' => $e->getMessage(), 'code' => 500]]]);
        }

        if (!$ok) {
            $this->send(422, ['errors' => [['title' => (string) $model->getError(), 'code' => 422]]]);
        }

        $savedId = $id ?: (int) $model->getId();

        if ($asArticle) {
            $title = (string) $db->setQuery($db->getQuery(true)->select('title')->from('#__content')->where('id = ' . $savedId))->loadResult();
            $this->send($id ? 200 : 201, ['data' => ['type' => 'articles', 'id' => (string) $savedId, 'attributes' => ['id' => $savedId, 'title' => $title]]]);
        }

        $this->send($id ? 200 : 201, ['data' => ['type' => 'flexicontent-items', 'id' => (string) $savedId]]);
    }

    /**
     * Maps a core article body onto what saveItem() reads: Joomla tag ids become tag names, a new item gets the
     * default FLEXIcontent type, and `com_fields` / `images` (which have no FLEXIcontent counterpart here) are dropped.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function fromCoreArticle(array $body, bool $isNew): array
    {
        $db = Factory::getDbo();

        if (!empty($body['tags']) && \is_array($body['tags'])) {
            $ids   = array_values(array_filter(array_map('intval', $body['tags'])));
            $names = $ids ? $db->setQuery($db->getQuery(true)->select('title')->from('#__tags')->where('id IN (' . implode(',', $ids) . ')'))->loadColumn() : [];

            $body['tags'] = $names;
        }

        if ($isNew && empty($body['type_id'])) {
            $default = (int) \Joomla\CMS\Component\ComponentHelper::getParams('com_flexicontent')->get('jarticle_form_typeid', 1);
            $exists  = (int) $db->setQuery($db->getQuery(true)->select('COUNT(*)')->from('#__flexicontent_types')->where('id = ' . $default))->loadResult();

            if (!$exists) {
                $default = (int) $db->setQuery($db->getQuery(true)->select('MIN(id)')->from('#__flexicontent_types')->where('published = 1'))->loadResult();
            }

            $body['type_id'] = $default;
        }

        unset($body['com_fields'], $body['images'], $body['version_note'], $body['created_by_alias']);

        return $body;
    }

    /** Tag names to FLEXIcontent tag ids, creating missing tags. */
    private function resolveTags(array $names): array
    {
        $db  = Factory::getDbo();
        $ids = [];

        foreach ($names as $name) {
            $name = trim((string) $name);

            if ($name === '') {
                continue;
            }

            $tid = (int) $db->setQuery($db->getQuery(true)->select('id')->from('#__flexicontent_tags')->where('name = ' . $db->quote($name)))->loadResult();

            if (!$tid) {
                $row = (object) ['name' => $name, 'alias' => \Joomla\CMS\Filter\OutputFilter::stringUrlUnicodeSlug($name), 'published' => 1];
                $db->insertObject('#__flexicontent_tags', $row, 'id');
                $tid = (int) $row->id;
            }

            $ids[] = $tid;
        }

        return $ids;
    }

    /**
     * FLEXIcontent builds form and class paths from JPATH_BASE, which is /api in this application.
     * Provide the form files it looks for there.
     */
    private function ensureShims(): void
    {
        $map = [
            JPATH_ADMINISTRATOR . '/components/com_flexicontent/models/forms/item.xml' => JPATH_API . '/components/com_flexicontent/forms/item.xml',
            JPATH_ADMINISTRATOR . '/components/com_content/forms/article.xml'          => JPATH_API . '/components/com_content/forms/article.xml',
        ];

        foreach ($map as $from => $to) {
            if (!is_file($to) && is_file($from)) {
                @mkdir(\dirname($to), 0755, true);

                if (!@copy($from, $to)) {
                    $this->send(500, ['errors' => [['title' => 'Cannot create ' . $to . ' (check permissions)', 'code' => 500]]]);
                }
            }
        }
    }

    private function loadFlexicontent(): void
    {
        // Legacy constants that component code expects, undefined in the API application.
        defined('JPATH_COMPONENT') or define('JPATH_COMPONENT', JPATH_ADMINISTRATOR . '/components/com_flexicontent');
        defined('JPATH_COMPONENT_SITE') or define('JPATH_COMPONENT_SITE', JPATH_SITE . '/components/com_flexicontent');
        defined('JPATH_COMPONENT_ADMINISTRATOR') or define('JPATH_COMPONENT_ADMINISTRATOR', JPATH_ADMINISTRATOR . '/components/com_flexicontent');

        Factory::getLanguage()->load('com_flexicontent', JPATH_ADMINISTRATOR);
        Table::addIncludePath(JPATH_ADMINISTRATOR . '/components/com_flexicontent/tables');

        require_once JPATH_ADMINISTRATOR . '/components/com_flexicontent/defineconstants.php';
        require_once JPATH_SITE . '/components/com_flexicontent/classes/flexicontent.fields.php';
        require_once JPATH_SITE . '/components/com_flexicontent/classes/flexicontent.helper.php';
        require_once JPATH_SITE . '/components/com_flexicontent/classes/flexicontent.categories.php';
        require_once JPATH_SITE . '/components/com_flexicontent/helpers/permission.php';
    }

    /**
     * Image field value(s): `{"file": "<name returned by POST /images>", "alt": "", "title": "", "desc": ""}` or a list of
     * those. The file must already be in FLEXIcontent's upload folder; anything else is refused, since a bare string
     * would be stored as a broken image record.
     *
     * @return list<array<string, string>>
     */
    private function imageValues(string $field, mixed $value): array
    {
        $items = \is_array($value) && !isset($value['file']) ? $value : [$value];
        $out   = [];

        foreach ($items as $item) {
            if (!\is_array($item) || !isset($item['file']) || !\is_string($item['file'])) {
                $this->send(422, ['errors' => [['title' => 'Image field "' . $field . '" takes {"file": <name from POST /v1/flexicontent/images>, "alt", "title", "desc"}', 'code' => 422]]]);
            }

            $name = basename($item['file']);

            if ($name !== $item['file'] || !is_file(COM_FLEXICONTENT_FILEPATH . DIRECTORY_SEPARATOR . $name)) {
                $this->send(422, ['errors' => [['title' => 'Image "' . $item['file'] . '" was not uploaded; send it to POST /v1/flexicontent/images first', 'code' => 422]]]);
            }

            $out[] = [
                'originalname' => '',
                'existingname' => $name,
                'alt'          => (string) ($item['alt'] ?? ''),
                'title'        => (string) ($item['title'] ?? ''),
                'desc'         => (string) ($item['desc'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * POST /v1/flexicontent/images  {"filename": "photo.jpg", "content": "<base64>"} -> {"data": {"file": "photo.jpg"}}.
     * Stores the picture in FLEXIcontent's upload folder and registers it in its file list, as an upload from the item
     * form would. Only real JPEG, PNG, GIF and WebP pictures up to 10 MB are accepted.
     */
    private function uploadImage(): void
    {
        $app  = $this->getApplication();
        $user = $app->getIdentity();
        $body = json_decode((string) file_get_contents('php://input'), true);

        if (!$user->authorise('core.create', 'com_content') && !$user->authorise('core.create', 'com_flexicontent') && !$user->authorise('core.edit', 'com_content')) {
            $this->send(403, ['errors' => [['title' => 'Not allowed to upload', 'code' => 403]]]);
        }

        if (!\is_array($body) || !isset($body['filename'], $body['content']) || !\is_string($body['filename']) || !\is_string($body['content'])) {
            $this->send(400, ['errors' => [['title' => 'Body must be {"filename": ..., "content": <base64>}', 'code' => 400]]]);
        }

        $bytes = base64_decode($body['content'], true);

        if ($bytes === false || $bytes === '' || \strlen($bytes) > 10 * 1024 * 1024) {
            $this->send(422, ['errors' => [['title' => 'Content must be base64 and at most 10 MB', 'code' => 422]]]);
        }

        $mime    = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];

        if (!isset($allowed[$mime]) || @getimagesizefromstring($bytes) === false) {
            $this->send(422, ['errors' => [['title' => 'Only JPEG, PNG, GIF and WebP pictures are accepted', 'code' => 422]]]);
        }

        $this->ensureShims();
        $this->loadFlexicontent();

        // The extension follows the real type, never the name the client gave.
        $base = pathinfo(\Joomla\Filesystem\File::makeSafe($body['filename']), PATHINFO_FILENAME) ?: 'image';
        $dir  = COM_FLEXICONTENT_FILEPATH . DIRECTORY_SEPARATOR;

        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            $this->send(500, ['errors' => [['title' => 'Cannot create the upload folder', 'code' => 500]]]);
        }

        $filename = \flexicontent_upload::sanitize($dir, $base . '.' . $allowed[$mime]);

        if (@file_put_contents($dir . $filename, $bytes) === false) {
            $this->send(500, ['errors' => [['title' => 'Cannot write the file', 'code' => 500]]]);
        }

        $db  = Factory::getDbo();
        $row = (object) [
            'filename'    => $filename,
            'altname'     => (string) $body['filename'],
            'url'         => 0,
            'secure'      => 1,
            'access'      => 1,
            'ext'         => $allowed[$mime],
            'hits'        => 0,
            'uploaded'    => Factory::getDate()->toSql(),
            'uploaded_by' => (int) $user->id,
        ];
        $db->insertObject('#__flexicontent_files', $row);

        $this->send(201, ['data' => ['type' => 'flexicontent-images', 'id' => $filename, 'attributes' => ['file' => $filename]]]);
    }

    private function send(int $status, array $payload): never
    {
        $app = $this->getApplication();
        $app->setHeader('status', (string) $status);
        $app->setHeader('Content-Type', 'application/vnd.api+json; charset=utf-8');
        $app->sendHeaders();
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $app->close();
        exit;
    }
}
