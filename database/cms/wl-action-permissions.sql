-- Run after the content navigation SQL. Defines actions only, never grants tenant roles.
-- Existing IDs, permissions and role assignments are preserved. Database names are translation keys.
BEGIN;
LOCK TABLE control_plane.wl_menu IN EXCLUSIVE MODE;
DO $cms$
DECLARE
  leaf RECORD;
  action RECORD;
  next_id integer;
BEGIN
  FOR leaf IN SELECT id, perms FROM control_plane.wl_menu
    WHERE deleted_at IS NULL AND perms IN (
      'cms_news_category:lists','cms_news:lists','cms_academy_category:lists','cms_academy:lists',
      'cms_help_category:lists','cms_help:lists','cms_announcement_category:lists','cms_announcement:lists','cms_translation:lists'
    )
  LOOP
    FOR action IN SELECT * FROM (VALUES
      ('add','cmsAdd',10),('edit','cmsEdit',20),('delete','cmsDelete',30),
      ('publish','cmsPublish',40),('translate','cmsTranslate',50),('config','cmsTranslationConfig',60)
    ) AS actions(verb, label, sort_order)
    LOOP
      IF leaf.perms = 'cms_translation:lists' THEN
        CONTINUE WHEN action.verb NOT IN ('edit','translate','config');
      ELSE
        CONTINUE WHEN action.verb IN ('translate','config');
        CONTINUE WHEN leaf.perms LIKE '%_category:lists' AND action.verb = 'publish';
      END IF;
      IF NOT EXISTS (SELECT 1 FROM control_plane.wl_menu WHERE deleted_at IS NULL AND perms = replace(leaf.perms, ':lists', ':' || action.verb)) THEN
        SELECT coalesce(max(id),0)+1 INTO next_id FROM control_plane.wl_menu;
        INSERT INTO control_plane.wl_menu(id,pid,type,name,perms,sort,is_show,is_disable)
        VALUES(next_id,leaf.id,'A',action.label,replace(leaf.perms,':lists',':' || action.verb),action.sort_order,0,0);
      END IF;
    END LOOP;
  END LOOP;
  PERFORM setval('control_plane.wl_menu_id_seq'::regclass,
    greatest((SELECT coalesce(max(id),1) FROM control_plane.wl_menu), (SELECT last_value FROM control_plane.wl_menu_id_seq)),true);
END;
$cms$;
COMMIT;
