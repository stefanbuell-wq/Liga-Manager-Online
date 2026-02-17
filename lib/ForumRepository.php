<?php
/**
 * Forum Repository - Data Access Layer für das Forum
 */

require_once __DIR__ . '/LmoDatabase.php';

class ForumRepository
{
    private $pdo;

    // Berechtigungsstufen (aufsteigend)
    const PERMISSION_LEVELS = [
        'public' => 0,      // Jeder (auch Gäste)
        'registered' => 1,  // Registrierte Benutzer
        'editor' => 2,      // Redakteure
        'admin' => 3,       // Administratoren
        'none' => 99        // Niemand
    ];

    public function __construct()
    {
        $this->pdo = LmoDatabase::getInstance();
    }

    // ==================== BERECHTIGUNGEN ====================

    /**
     * Prüft ob ein Benutzer eine bestimmte Berechtigung hat
     * @param string $requiredPermission - public, registered, editor, admin, none
     * @param string|null $userRole - Rolle des Benutzers (null = Gast)
     * @return bool
     */
    public function hasPermission($requiredPermission, $userRole = null)
    {
        // 'none' bedeutet niemand hat Zugriff
        if ($requiredPermission === 'none') {
            return false;
        }

        // Gast-Level (nicht angemeldet)
        if ($userRole === null) {
            return $requiredPermission === 'public';
        }

        $requiredLevel = self::PERMISSION_LEVELS[$requiredPermission] ?? 99;
        $userLevel = self::PERMISSION_LEVELS[$userRole] ?? 0;

        // Admin hat immer Zugriff (außer bei 'none')
        if ($userRole === 'admin') {
            return true;
        }

        return $userLevel >= $requiredLevel;
    }

    /**
     * Gibt die effektive Benutzerrolle zurück
     */
    public function getUserRole($userId = null)
    {
        if ($userId === null) {
            return null; // Gast
        }

        $stmt = $this->pdo->prepare("SELECT role FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $role = $stmt->fetchColumn();

        return $role ?: 'user';
    }

    /**
     * Prüft Kategorie-Berechtigung
     */
    public function canViewCategory($categoryId, $userRole = null)
    {
        $cat = $this->getCategoryById($categoryId);
        if (!$cat) return false;
        return $this->hasPermission($cat['view_permission'] ?? 'public', $userRole);
    }

    public function canReplyInCategory($categoryId, $userRole = null)
    {
        $cat = $this->getCategoryById($categoryId);
        if (!$cat) return false;
        return $this->hasPermission($cat['reply_permission'] ?? 'registered', $userRole);
    }

    public function canCreateTopicInCategory($categoryId, $userRole = null)
    {
        $cat = $this->getCategoryById($categoryId);
        if (!$cat) return false;
        return $this->hasPermission($cat['create_permission'] ?? 'registered', $userRole);
    }

    // ==================== KATEGORIEN ====================

    /**
     * Alle Kategorien abrufen (gefiltert nach Berechtigung)
     * @param string|null $userRole - Rolle des Benutzers
     * @param bool $includeHidden - Auch versteckte Kategorien (für Admin)
     */
    public function getCategories($userRole = null, $includeHidden = false)
    {
        $stmt = $this->pdo->query("
            SELECT c.*,
                   (SELECT COUNT(*) FROM forum_topics WHERE category_id = c.id) as topic_count,
                   (SELECT COUNT(*) FROM forum_posts p
                    JOIN forum_topics t ON p.topic_id = t.id
                    WHERE t.category_id = c.id) as post_count
            FROM forum_categories c
            ORDER BY c.sort_order, c.name
        ");
        $categories = $stmt->fetchAll();

        // Filtern nach Berechtigung
        if (!$includeHidden) {
            $categories = array_filter($categories, function($cat) use ($userRole) {
                return $this->hasPermission($cat['view_permission'] ?? 'public', $userRole);
            });
        }

        return array_values($categories);
    }

    /**
     * Kategorien für Homepage abrufen (nur show_on_homepage = 1)
     */
    public function getHomepageCategories()
    {
        $stmt = $this->pdo->query("
            SELECT c.*
            FROM forum_categories c
            WHERE c.show_on_homepage = 1
              AND c.view_permission = 'public'
            ORDER BY c.sort_order, c.name
        ");
        return $stmt->fetchAll();
    }

    /**
     * Einzelne Kategorie abrufen
     */
    public function getCategoryById($id)
    {
        $stmt = $this->pdo->prepare("SELECT * FROM forum_categories WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    /**
     * Kategorie erstellen
     */
    public function createCategory($name, $description = '', $sortOrder = 0)
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO forum_categories (name, description, sort_order)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$name, $description, $sortOrder]);
        return $this->pdo->lastInsertId();
    }

    /**
     * Kategorie aktualisieren
     */
    public function updateCategory($id, $data)
    {
        // Wenn alte Signatur (4 Parameter) verwendet wird, konvertieren
        if (!is_array($data)) {
            $args = func_get_args();
            $data = [
                'name' => $args[1] ?? '',
                'description' => $args[2] ?? '',
                'sort_order' => $args[3] ?? 0
            ];
        }

        $fields = [];
        $values = [];

        $allowedFields = ['name', 'description', 'sort_order', 'view_permission',
                          'reply_permission', 'create_permission', 'show_on_homepage', 'is_archived'];

        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $fields[] = "$field = ?";
                $values[] = $data[$field];
            }
        }

        if (empty($fields)) {
            return false;
        }

        $values[] = $id;
        $sql = "UPDATE forum_categories SET " . implode(', ', $fields) . " WHERE id = ?";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($values);
    }

    /**
     * Kategorie-Berechtigungen aktualisieren
     */
    public function updateCategoryPermissions($id, $viewPerm, $replyPerm, $createPerm, $showOnHomepage = false, $isArchived = false)
    {
        $stmt = $this->pdo->prepare("
            UPDATE forum_categories
            SET view_permission = ?, reply_permission = ?, create_permission = ?,
                show_on_homepage = ?, is_archived = ?
            WHERE id = ?
        ");
        return $stmt->execute([$viewPerm, $replyPerm, $createPerm, $showOnHomepage ? 1 : 0, $isArchived ? 1 : 0, $id]);
    }

    /**
     * Kategorie löschen
     */
    public function deleteCategory($id)
    {
        $stmt = $this->pdo->prepare("DELETE FROM forum_categories WHERE id = ?");
        return $stmt->execute([$id]);
    }

    // ==================== TOPICS ====================

    /**
     * Topics einer Kategorie abrufen
     */
    public function getTopicsByCategory($categoryId, $limit = 20, $offset = 0)
    {
        $stmt = $this->pdo->prepare("
            SELECT t.*,
                   u.username as author_name,
                   u.display_name as author_display_name,
                   lu.username as last_post_author
            FROM forum_topics t
            LEFT JOIN users u ON t.user_id = u.id
            LEFT JOIN users lu ON t.last_post_user_id = lu.id
            WHERE t.category_id = ?
            ORDER BY t.is_sticky DESC, t.last_post_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$categoryId, $limit, $offset]);
        return $stmt->fetchAll();
    }

    /**
     * Topic-Anzahl einer Kategorie
     */
    public function getTopicCountByCategory($categoryId)
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM forum_topics WHERE category_id = ?");
        $stmt->execute([$categoryId]);
        return $stmt->fetchColumn();
    }

    /**
     * Einzelnes Topic abrufen
     */
    public function getTopicById($id)
    {
        $stmt = $this->pdo->prepare("
            SELECT t.*,
                   u.username as author_name,
                   u.display_name as author_display_name,
                   c.name as category_name
            FROM forum_topics t
            LEFT JOIN users u ON t.user_id = u.id
            LEFT JOIN forum_categories c ON t.category_id = c.id
            WHERE t.id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    /**
     * Topic erstellen
     */
    public function createTopic($categoryId, $userId, $title, $content)
    {
        $this->pdo->beginTransaction();

        try {
            // Topic erstellen
            $stmt = $this->pdo->prepare("
                INSERT INTO forum_topics (category_id, user_id, title, post_count, last_post_at)
                VALUES (?, ?, ?, 1, datetime('now'))
            ");
            $stmt->execute([$categoryId, $userId, $title]);
            $topicId = $this->pdo->lastInsertId();

            // Ersten Post erstellen
            $stmt = $this->pdo->prepare("
                INSERT INTO forum_posts (topic_id, user_id, content, is_first_post)
                VALUES (?, ?, ?, 1)
            ");
            $stmt->execute([$topicId, $userId, $content]);
            $postId = $this->pdo->lastInsertId();

            // Topic mit Post-ID aktualisieren
            $stmt = $this->pdo->prepare("
                UPDATE forum_topics
                SET last_post_id = ?, last_post_user_id = ?
                WHERE id = ?
            ");
            $stmt->execute([$postId, $userId, $topicId]);

            // Kategorie-Counter aktualisieren
            $this->updateCategoryCounters($categoryId);

            $this->pdo->commit();
            return $topicId;

        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Topic sperren/entsperren
     */
    public function lockTopic($id, $locked = true)
    {
        $stmt = $this->pdo->prepare("UPDATE forum_topics SET is_locked = ? WHERE id = ?");
        return $stmt->execute([$locked ? 1 : 0, $id]);
    }

    /**
     * Topic sticky/unsticky
     */
    public function stickyTopic($id, $sticky = true)
    {
        $stmt = $this->pdo->prepare("UPDATE forum_topics SET is_sticky = ? WHERE id = ?");
        return $stmt->execute([$sticky ? 1 : 0, $id]);
    }

    /**
     * Topic löschen
     */
    public function deleteTopic($id)
    {
        $topic = $this->getTopicById($id);
        if (!$topic) return false;

        $stmt = $this->pdo->prepare("DELETE FROM forum_topics WHERE id = ?");
        $result = $stmt->execute([$id]);

        // Kategorie-Counter aktualisieren
        $this->updateCategoryCounters($topic['category_id']);

        return $result;
    }

    /**
     * View-Counter erhöhen
     */
    public function incrementTopicViews($id)
    {
        $stmt = $this->pdo->prepare("UPDATE forum_topics SET view_count = view_count + 1 WHERE id = ?");
        return $stmt->execute([$id]);
    }

    // ==================== POSTS ====================

    /**
     * Posts eines Topics abrufen
     */
    public function getPostsByTopic($topicId, $limit = 20, $offset = 0)
    {
        $stmt = $this->pdo->prepare("
            SELECT p.*,
                   u.username as author_name,
                   u.display_name as author_display_name,
                   eu.username as editor_name
            FROM forum_posts p
            LEFT JOIN users u ON p.user_id = u.id
            LEFT JOIN users eu ON p.edited_by = eu.id
            WHERE p.topic_id = ?
            ORDER BY p.created_at ASC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$topicId, $limit, $offset]);
        return $stmt->fetchAll();
    }

    /**
     * Post-Anzahl eines Topics
     */
    public function getPostCountByTopic($topicId)
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM forum_posts WHERE topic_id = ?");
        $stmt->execute([$topicId]);
        return $stmt->fetchColumn();
    }

    /**
     * Einzelnen Post abrufen
     */
    public function getPostById($id)
    {
        $stmt = $this->pdo->prepare("
            SELECT p.*, t.title as topic_title, t.category_id
            FROM forum_posts p
            JOIN forum_topics t ON p.topic_id = t.id
            WHERE p.id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    /**
     * Post erstellen (Antwort)
     */
    public function createPost($topicId, $userId, $content, $guestName = null)
    {
        $topic = $this->getTopicById($topicId);
        if (!$topic) {
            throw new Exception("Topic nicht gefunden");
        }
        if ($topic['is_locked']) {
            throw new Exception("Topic ist gesperrt");
        }

        $this->pdo->beginTransaction();

        try {
            // Post erstellen
            $stmt = $this->pdo->prepare("
                INSERT INTO forum_posts (topic_id, user_id, guest_name, content)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$topicId, $userId, $guestName, $content]);
            $postId = $this->pdo->lastInsertId();

            // Topic aktualisieren
            $stmt = $this->pdo->prepare("
                UPDATE forum_topics
                SET post_count = post_count + 1,
                    last_post_id = ?,
                    last_post_at = datetime('now'),
                    last_post_user_id = ?
                WHERE id = ?
            ");
            $stmt->execute([$postId, $userId, $topicId]);

            // Kategorie-Counter aktualisieren
            $this->updateCategoryCounters($topic['category_id']);

            $this->pdo->commit();
            return $postId;

        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Post bearbeiten
     */
    public function updatePost($id, $content, $editorId)
    {
        $stmt = $this->pdo->prepare("
            UPDATE forum_posts
            SET content = ?, edited_at = datetime('now'), edited_by = ?
            WHERE id = ?
        ");
        return $stmt->execute([$content, $editorId, $id]);
    }

    /**
     * Post löschen
     */
    public function deletePost($id)
    {
        $post = $this->getPostById($id);
        if (!$post) return false;

        // Ersten Post nicht löschen (Topic löschen stattdessen)
        if ($post['is_first_post']) {
            throw new Exception("Erster Post kann nicht gelöscht werden. Lösche stattdessen das Topic.");
        }

        $stmt = $this->pdo->prepare("DELETE FROM forum_posts WHERE id = ?");
        $result = $stmt->execute([$id]);

        // Topic-Counter aktualisieren
        $this->updateTopicCounters($post['topic_id']);
        $this->updateCategoryCounters($post['category_id']);

        return $result;
    }

    // ==================== HILFSMETHODEN ====================

    /**
     * Kategorie-Counter aktualisieren
     */
    private function updateCategoryCounters($categoryId)
    {
        $stmt = $this->pdo->prepare("
            UPDATE forum_categories
            SET topic_count = (SELECT COUNT(*) FROM forum_topics WHERE category_id = ?),
                post_count = (SELECT COUNT(*) FROM forum_posts p
                              JOIN forum_topics t ON p.topic_id = t.id
                              WHERE t.category_id = ?),
                last_post_id = (SELECT p.id FROM forum_posts p
                                JOIN forum_topics t ON p.topic_id = t.id
                                WHERE t.category_id = ?
                                ORDER BY p.created_at DESC LIMIT 1)
            WHERE id = ?
        ");
        $stmt->execute([$categoryId, $categoryId, $categoryId, $categoryId]);
    }

    /**
     * Topic-Counter aktualisieren
     */
    private function updateTopicCounters($topicId)
    {
        $stmt = $this->pdo->prepare("
            UPDATE forum_topics
            SET post_count = (SELECT COUNT(*) FROM forum_posts WHERE topic_id = ?),
                last_post_id = (SELECT id FROM forum_posts WHERE topic_id = ? ORDER BY created_at DESC LIMIT 1),
                last_post_at = (SELECT created_at FROM forum_posts WHERE topic_id = ? ORDER BY created_at DESC LIMIT 1),
                last_post_user_id = (SELECT user_id FROM forum_posts WHERE topic_id = ? ORDER BY created_at DESC LIMIT 1)
            WHERE id = ?
        ");
        $stmt->execute([$topicId, $topicId, $topicId, $topicId, $topicId]);
    }

    /**
     * Neueste Topics abrufen (für Forum-Übersicht)
     * @param int $limit
     * @param string|null $userRole - Benutzerrolle für Filterung
     */
    public function getRecentTopics($limit = 10, $userRole = null)
    {
        // Basis-Query
        $sql = "
            SELECT t.*,
                   c.name as category_name,
                   c.view_permission,
                   u.username as author_name,
                   u.display_name as author_display_name
            FROM forum_topics t
            LEFT JOIN forum_categories c ON t.category_id = c.id
            LEFT JOIN users u ON t.user_id = u.id
            WHERE 1=1
        ";

        // Berechtigungsfilter
        if ($userRole === null) {
            $sql .= " AND c.view_permission = 'public'";
        } elseif ($userRole !== 'admin') {
            $permLevels = ['public'];
            if (in_array($userRole, ['user', 'editor', 'admin'])) $permLevels[] = 'registered';
            if (in_array($userRole, ['editor', 'admin'])) $permLevels[] = 'editor';
            $sql .= " AND c.view_permission IN ('" . implode("','", $permLevels) . "')";
        }

        $sql .= " ORDER BY t.last_post_at DESC LIMIT ?";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }

    /**
     * Topics für Homepage abrufen (nur aus öffentlichen Kategorien mit show_on_homepage=1)
     */
    public function getHomepageTopics($limit = 10)
    {
        $stmt = $this->pdo->prepare("
            SELECT t.*,
                   c.name as category_name,
                   u.username as author_name,
                   u.display_name as author_display_name
            FROM forum_topics t
            JOIN forum_categories c ON t.category_id = c.id
            LEFT JOIN users u ON t.user_id = u.id
            WHERE c.show_on_homepage = 1
              AND c.view_permission = 'public'
              AND c.is_archived = 0
            ORDER BY t.last_post_at DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }

    /**
     * Forum-Statistiken
     */
    public function getStats()
    {
        return [
            'categories' => $this->pdo->query("SELECT COUNT(*) FROM forum_categories")->fetchColumn(),
            'topics' => $this->pdo->query("SELECT COUNT(*) FROM forum_topics")->fetchColumn(),
            'posts' => $this->pdo->query("SELECT COUNT(*) FROM forum_posts")->fetchColumn(),
        ];
    }

    /**
     * Benutzerrolle aktualisieren
     */
    public function updateUserRole($userId, $role)
    {
        $validRoles = ['user', 'editor', 'admin'];
        if (!in_array($role, $validRoles)) {
            throw new Exception("Ungültige Rolle: $role");
        }

        $stmt = $this->pdo->prepare("UPDATE users SET role = ? WHERE id = ?");
        return $stmt->execute([$role, $userId]);
    }

    /**
     * Alle Benutzer mit Rollen abrufen
     */
    public function getUsersWithRoles($limit = 100, $offset = 0, $search = '')
    {
        $params = [];
        $sql = "
            SELECT id, username, display_name, email, role, active, created_at, last_login
            FROM users
        ";

        if ($search) {
            $sql .= " WHERE username LIKE ? OR display_name LIKE ? OR email LIKE ?";
            $searchTerm = '%' . $search . '%';
            $params = [$searchTerm, $searchTerm, $searchTerm];
        }

        $sql .= "
            ORDER BY
                CASE role
                    WHEN 'admin' THEN 1
                    WHEN 'editor' THEN 2
                    ELSE 3
                END,
                username
            LIMIT ? OFFSET ?
        ";
        $params[] = $limit;
        $params[] = $offset;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Benutzer zählen (mit optionaler Suche)
     */
    public function getUserCount($search = '')
    {
        if ($search) {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) FROM users
                WHERE username LIKE ? OR display_name LIKE ? OR email LIKE ?
            ");
            $searchTerm = '%' . $search . '%';
            $stmt->execute([$searchTerm, $searchTerm, $searchTerm]);
        } else {
            $stmt = $this->pdo->query("SELECT COUNT(*) FROM users");
        }
        return $stmt->fetchColumn();
    }

    /**
     * Benutzer nach Rolle zählen
     */
    public function countUsersByRole()
    {
        $stmt = $this->pdo->query("
            SELECT role, COUNT(*) as count
            FROM users
            GROUP BY role
        ");
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[$row['role'] ?? 'user'] = $row['count'];
        }
        return $result;
    }

    // ==================== LIGA-MANAGER INTEGRATION ====================

    /**
     * Verknüpfung zwischen Match/Spieltag und Forum-Topic erstellen
     * @param int $forumTopicId - Das Forum-Topic
     * @param int|null $leagueId - Die Liga
     * @param int|null $roundNr - Der Spieltag (optional)
     * @param int|null $matchId - Das Spiel (optional)
     * @param string $linkType - Art der Verknüpfung: 'discussion', 'preview', 'report', 'matchday'
     * @param int|null $userId - Ersteller
     * @param bool $autoGenerated - Automatisch generiert?
     */
    public function createMatchForumLink($forumTopicId, $leagueId = null, $roundNr = null, $matchId = null, $linkType = 'discussion', $userId = null, $autoGenerated = false)
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO match_forum_links (league_id, round_nr, match_id, forum_topic_id, link_type, created_by, auto_generated)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$leagueId, $roundNr, $matchId, $forumTopicId, $linkType, $userId, $autoGenerated ? 1 : 0]);
        return $this->pdo->lastInsertId();
    }

    /**
     * Forum-Links für ein Spiel abrufen
     */
    public function getForumLinksForMatch($matchId)
    {
        $stmt = $this->pdo->prepare("
            SELECT mfl.*, ft.title as topic_title, ft.post_count, ft.view_count
            FROM match_forum_links mfl
            JOIN forum_topics ft ON mfl.forum_topic_id = ft.id
            WHERE mfl.match_id = ?
            ORDER BY mfl.created_at DESC
        ");
        $stmt->execute([$matchId]);
        return $stmt->fetchAll();
    }

    /**
     * Forum-Links für einen Spieltag abrufen
     */
    public function getForumLinksForRound($leagueId, $roundNr)
    {
        $stmt = $this->pdo->prepare("
            SELECT mfl.*, ft.title as topic_title, ft.post_count, ft.view_count
            FROM match_forum_links mfl
            JOIN forum_topics ft ON mfl.forum_topic_id = ft.id
            WHERE mfl.league_id = ? AND mfl.round_nr = ?
            ORDER BY mfl.created_at DESC
        ");
        $stmt->execute([$leagueId, $roundNr]);
        return $stmt->fetchAll();
    }

    /**
     * Forum-Links für eine Liga abrufen
     */
    public function getForumLinksForLeague($leagueId, $limit = 20)
    {
        $stmt = $this->pdo->prepare("
            SELECT mfl.*, ft.title as topic_title, ft.post_count, ft.view_count, ft.last_post_at
            FROM match_forum_links mfl
            JOIN forum_topics ft ON mfl.forum_topic_id = ft.id
            WHERE mfl.league_id = ?
            ORDER BY ft.last_post_at DESC
            LIMIT ?
        ");
        $stmt->execute([$leagueId, $limit]);
        return $stmt->fetchAll();
    }

    /**
     * Forum-Link löschen
     */
    public function deleteMatchForumLink($id)
    {
        $stmt = $this->pdo->prepare("DELETE FROM match_forum_links WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Kategorie für eine Liga erstellen/abrufen
     */
    public function getOrCreateLeagueCategory($leagueId, $leagueName)
    {
        // Prüfen ob bereits eine Kategorie existiert
        $stmt = $this->pdo->prepare("SELECT * FROM forum_categories WHERE league_id = ?");
        $stmt->execute([$leagueId]);
        $existing = $stmt->fetch();

        if ($existing) {
            return $existing;
        }

        // Neue Kategorie erstellen
        $stmt = $this->pdo->prepare("
            INSERT INTO forum_categories (name, description, league_id, sort_order, view_permission, reply_permission, create_permission)
            VALUES (?, ?, ?, 100, 'public', 'registered', 'registered')
        ");
        $stmt->execute([$leagueName, "Diskussionen zur $leagueName", $leagueId]);

        return $this->getCategoryById($this->pdo->lastInsertId());
    }

    /**
     * Spieltag-Topic erstellen
     */
    public function createMatchdayTopic($leagueId, $roundNr, $leagueName, $categoryId, $userId = null, $content = '')
    {
        $title = "$leagueName - $roundNr. Spieltag";

        if (empty($content)) {
            $content = "Diskussion zum $roundNr. Spieltag der $leagueName.\n\nHier können Ergebnisse, Spielberichte und Kommentare gepostet werden.";
        }

        $this->pdo->beginTransaction();

        try {
            // Topic erstellen
            $stmt = $this->pdo->prepare("
                INSERT INTO forum_topics (category_id, user_id, title, league_id, round_nr, topic_type, post_count, last_post_at)
                VALUES (?, ?, ?, ?, ?, 'matchday', 1, datetime('now'))
            ");
            $stmt->execute([$categoryId, $userId, $title, $leagueId, $roundNr]);
            $topicId = $this->pdo->lastInsertId();

            // Ersten Post erstellen
            $stmt = $this->pdo->prepare("
                INSERT INTO forum_posts (topic_id, user_id, content, is_first_post)
                VALUES (?, ?, ?, 1)
            ");
            $stmt->execute([$topicId, $userId, $content]);
            $postId = $this->pdo->lastInsertId();

            // Topic aktualisieren
            $stmt = $this->pdo->prepare("
                UPDATE forum_topics SET last_post_id = ?, last_post_user_id = ? WHERE id = ?
            ");
            $stmt->execute([$postId, $userId, $topicId]);

            // Verknüpfung erstellen
            $this->createMatchForumLink($topicId, $leagueId, $roundNr, null, 'matchday', $userId, true);

            // Kategorie-Counter aktualisieren
            $this->updateCategoryCounters($categoryId);

            $this->pdo->commit();
            return $topicId;

        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Spielbericht-Topic erstellen
     */
    public function createMatchReportTopic($matchId, $categoryId, $title, $content, $userId = null)
    {
        // Match-Daten laden für Liga-Verknüpfung
        $stmt = $this->pdo->prepare("SELECT league_id, round_nr FROM matches WHERE id = ?");
        $stmt->execute([$matchId]);
        $match = $stmt->fetch();

        if (!$match) {
            throw new Exception("Match nicht gefunden");
        }

        $this->pdo->beginTransaction();

        try {
            // Topic erstellen
            $stmt = $this->pdo->prepare("
                INSERT INTO forum_topics (category_id, user_id, title, league_id, round_nr, match_id, topic_type, post_count, last_post_at)
                VALUES (?, ?, ?, ?, ?, ?, 'report', 1, datetime('now'))
            ");
            $stmt->execute([$categoryId, $userId, $title, $match['league_id'], $match['round_nr'], $matchId]);
            $topicId = $this->pdo->lastInsertId();

            // Ersten Post erstellen
            $stmt = $this->pdo->prepare("
                INSERT INTO forum_posts (topic_id, user_id, content, is_first_post)
                VALUES (?, ?, ?, 1)
            ");
            $stmt->execute([$topicId, $userId, $content]);
            $postId = $this->pdo->lastInsertId();

            // Topic aktualisieren
            $stmt = $this->pdo->prepare("
                UPDATE forum_topics SET last_post_id = ?, last_post_user_id = ? WHERE id = ?
            ");
            $stmt->execute([$postId, $userId, $topicId]);

            // Verknüpfung erstellen
            $this->createMatchForumLink($topicId, $match['league_id'], $match['round_nr'], $matchId, 'report', $userId, false);

            // Kategorie-Counter aktualisieren
            $this->updateCategoryCounters($categoryId);

            $this->pdo->commit();
            return $topicId;

        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Topics für eine Liga abrufen
     */
    public function getTopicsByLeague($leagueId, $limit = 20, $offset = 0)
    {
        $stmt = $this->pdo->prepare("
            SELECT t.*,
                   c.name as category_name,
                   u.username as author_name,
                   u.display_name as author_display_name
            FROM forum_topics t
            LEFT JOIN forum_categories c ON t.category_id = c.id
            LEFT JOIN users u ON t.user_id = u.id
            WHERE t.league_id = ?
            ORDER BY t.last_post_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$leagueId, $limit, $offset]);
        return $stmt->fetchAll();
    }

    /**
     * Topics für einen Spieltag abrufen
     */
    public function getTopicsByMatchday($leagueId, $roundNr)
    {
        $stmt = $this->pdo->prepare("
            SELECT t.*,
                   c.name as category_name,
                   u.username as author_name,
                   u.display_name as author_display_name
            FROM forum_topics t
            LEFT JOIN forum_categories c ON t.category_id = c.id
            LEFT JOIN users u ON t.user_id = u.id
            WHERE t.league_id = ? AND t.round_nr = ?
            ORDER BY t.topic_type, t.created_at DESC
        ");
        $stmt->execute([$leagueId, $roundNr]);
        return $stmt->fetchAll();
    }

    /**
     * Topics für ein Spiel abrufen
     */
    public function getTopicsByMatch($matchId)
    {
        $stmt = $this->pdo->prepare("
            SELECT t.*,
                   c.name as category_name,
                   u.username as author_name,
                   u.display_name as author_display_name
            FROM forum_topics t
            LEFT JOIN forum_categories c ON t.category_id = c.id
            LEFT JOIN users u ON t.user_id = u.id
            WHERE t.match_id = ?
            ORDER BY t.topic_type, t.created_at DESC
        ");
        $stmt->execute([$matchId]);
        return $stmt->fetchAll();
    }

    /**
     * Alle Ligen mit Forum-Kategorien abrufen
     */
    public function getLeaguesWithCategories()
    {
        $stmt = $this->pdo->query("
            SELECT l.*, fc.id as category_id, fc.name as category_name,
                   fc.topic_count, fc.post_count
            FROM leagues l
            LEFT JOIN forum_categories fc ON fc.league_id = l.id
            ORDER BY l.name
        ");
        return $stmt->fetchAll();
    }

    /**
     * Prüfen ob Spieltag-Topic existiert
     */
    public function matchdayTopicExists($leagueId, $roundNr)
    {
        $stmt = $this->pdo->prepare("
            SELECT id FROM forum_topics
            WHERE league_id = ? AND round_nr = ? AND topic_type = 'matchday'
            LIMIT 1
        ");
        $stmt->execute([$leagueId, $roundNr]);
        return $stmt->fetchColumn() !== false;
    }

    // ==================== LEGACY MIGRATION ====================

    /**
     * Legacy-ID speichern (für SMF-Migration)
     */
    public function saveLegacyMapping($entityType, $oldId, $newId)
    {
        $stmt = $this->pdo->prepare("
            INSERT OR REPLACE INTO forum_legacy_map (entity_type, old_id, new_id)
            VALUES (?, ?, ?)
        ");
        return $stmt->execute([$entityType, $oldId, $newId]);
    }

    /**
     * Neue ID für Legacy-ID finden
     */
    public function getNewIdFromLegacy($entityType, $oldId)
    {
        $stmt = $this->pdo->prepare("
            SELECT new_id FROM forum_legacy_map
            WHERE entity_type = ? AND old_id = ?
        ");
        $stmt->execute([$entityType, $oldId]);
        return $stmt->fetchColumn();
    }
}
