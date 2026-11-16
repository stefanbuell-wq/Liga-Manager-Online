/**
 * HAFO.de Forum JavaScript
 * Handles forum categories, topics, and posts
 */

const API_BASE = 'api';

// State
let currentUser = null;
let csrfToken = null;
let currentView = 'categories'; // 'categories', 'topics', 'topic'
let currentCategoryId = null;
let currentTopicId = null;
let currentPage = 1;
let totalPages = 1;

// Suche (URL-Parameter q) und Sortierung (pro Besucher gemerkt)
let searchQuery = '';
let postSort = prefGet('hafo_forum_post_sort', ['new', 'old'], 'new');          // Beitraege: neueste zuerst
let topicSort = prefGet('hafo_forum_topic_sort', ['activity', 'newest', 'oldest', 'replies'], 'activity');
let allPostsInTopic = 0;

const TOPIC_SORTS = [
    ['activity', 'Letzte Antwort'],
    ['newest', 'Neueste Themen'],
    ['oldest', 'Älteste Themen'],
    ['replies', 'Meiste Beiträge']
];

function prefGet(key, allowed, fallback) {
    try {
        const v = localStorage.getItem(key);
        return allowed.includes(v) ? v : fallback;
    } catch (e) {
        return fallback;
    }
}

function prefSet(key, value) {
    try { localStorage.setItem(key, value); } catch (e) {}
}

// ============================================
// INITIALIZATION
// ============================================

document.addEventListener('DOMContentLoaded', () => {
    checkAuth().finally(parseUrl);
});

// Handle browser back/forward
window.addEventListener('popstate', () => {
    parseUrl();
});

/**
 * Parse URL and load appropriate view
 */
function parseUrl() {
    const params = new URLSearchParams(window.location.search);
    const categoryId = params.get('category');
    const topicId = params.get('topic');
    currentPage = parseInt(params.get('page')) || 1;
    searchQuery = (params.get('q') || '').trim();

    if (topicId) {
        loadTopic(parseInt(topicId));
    } else if (categoryId) {
        loadCategory(parseInt(categoryId));
    } else if (searchQuery) {
        loadSearch();
    } else {
        loadCategories();
    }
}

// ============================================
// SUCHE & SORTIERUNG
// ============================================

/**
 * Leiste mit Suchfeld und Sortierung fuer die aktuelle Ansicht
 * @param {string} view categories | topics | search | topic
 * @param {string} info Hinweiszeile (z. B. Trefferzahl), bereits kodiert
 */
function renderToolbar(view, info = '') {
    const el = document.getElementById('forum-toolbar');
    if (!el) return;

    const placeholder = {
        categories: 'Ganzes Forum durchsuchen …',
        search: 'Ganzes Forum durchsuchen …',
        topics: 'In diesem Bereich suchen …',
        topic: 'In diesem Thema suchen …'
    }[view];

    let sortHtml = '';
    if (view === 'topic') {
        sortHtml = `
            <div class="forum-sort">
                <span>Reihenfolge:</span>
                <span class="seg" role="group" aria-label="Reihenfolge der Beiträge">
                    <button type="button" class="${postSort === 'new' ? 'active' : ''}" aria-pressed="${postSort === 'new'}" onclick="setPostSort('new')">Neueste zuerst</button>
                    <button type="button" class="${postSort === 'old' ? 'active' : ''}" aria-pressed="${postSort === 'old'}" onclick="setPostSort('old')">Älteste zuerst</button>
                </span>
            </div>`;
    } else if (view === 'topics' || view === 'search') {
        sortHtml = `
            <label class="forum-sort">
                <span>Sortieren:</span>
                <select onchange="setTopicSort(this.value)">
                    ${TOPIC_SORTS.map(([v, l]) => `<option value="${v}" ${topicSort === v ? 'selected' : ''}>${l}</option>`).join('')}
                </select>
            </label>`;
    }

    el.innerHTML = `
        <div class="forum-toolbar">
            <form class="forum-search" role="search" onsubmit="event.preventDefault(); setSearch(this.q.value);">
                <input type="search" name="q" value="${escapeAttr(searchQuery)}" placeholder="${placeholder}" aria-label="${placeholder}">
                <button type="submit" class="btn btn-secondary">Suchen</button>
            </form>
            ${sortHtml}
            ${currentUser && view !== 'topic' ? `<button type="button" class="btn btn-ghost mark-read-btn" onclick="markAllRead()" title="Alle Themen als gelesen markieren">✓ Alles gelesen</button>` : ''}
            ${info ? `<div class="forum-search-info">${info}</div>` : ''}
        </div>`;
}

/** Hinweis "N Treffer fuer ... · Suche aufheben" */
function searchInfo(count, singular, plural) {
    if (!searchQuery) return '';
    return `${count} ${count === 1 ? singular : plural} für „${escapeHtml(searchQuery)}“ · <a onclick="setSearch('')">Suche aufheben</a>`;
}

/** URL-Parameter setzen (null = entfernen) und Ansicht neu laden */
function updateUrl(changes) {
    const params = new URLSearchParams(window.location.search);
    for (const [k, v] of Object.entries(changes)) {
        if (v === null || v === '' || (k === 'page' && v === 1)) params.delete(k);
        else params.set(k, v);
    }
    history.pushState({}, '', 'forum.html' + (params.toString() ? '?' + params.toString() : ''));
    parseUrl();
}

function setSearch(q) {
    updateUrl({ q: (q || '').trim(), page: null });
}

function setPostSort(value) {
    if (value === postSort) return;
    postSort = value;
    prefSet('hafo_forum_post_sort', value);
    updateUrl({ page: null });
}

function setTopicSort(value) {
    topicSort = value;
    prefSet('hafo_forum_topic_sort', value);
    updateUrl({ page: null });
}

/** Suchwoerter im Text der Beitraege markieren (nur Textknoten, kein HTML) */
function highlightMatches(container, query) {
    const words = (query.match(/[\p{L}\p{N}]{2,}/gu) || []).slice(0, 6);
    if (!words.length || !container) return;
    const re = new RegExp('(?<![\\p{L}\\p{N}])(' + words.map(w => w.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('|') + ')', 'giu');
    container.querySelectorAll('.post-content').forEach(root => {
        const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
        const nodes = [];
        while (walker.nextNode()) nodes.push(walker.currentNode);
        nodes.forEach(node => {
            const text = node.nodeValue;
            re.lastIndex = 0;
            if (!re.test(text)) return;
            re.lastIndex = 0;
            const frag = document.createDocumentFragment();
            let last = 0, m;
            while ((m = re.exec(text))) {
                frag.append(text.slice(last, m.index));
                const mark = document.createElement('mark');
                mark.textContent = m[0];
                frag.append(mark);
                last = m.index + m[0].length;
            }
            frag.append(text.slice(last));
            node.replaceWith(frag);
        });
    });
}

/**
 * Suche ueber das ganze Forum (Themen mit Treffern im Titel oder in Beitraegen)
 */
async function loadSearch() {
    currentView = 'search';
    currentCategoryId = null;
    currentTopicId = null;
    document.getElementById('new-topic-btn').style.display = 'none';
    updateBreadcrumb([{ label: 'Suche', url: '' }]);
    document.getElementById('forum-title').innerHTML = 'Forum <span>durchsuchen</span>';

    try {
        const res = await fetch(`${API_BASE}/forum-topics.php?q=${encodeURIComponent(searchQuery)}&sort=${topicSort}&limit=20&offset=${(currentPage - 1) * 20}`);
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Fehler beim Laden');
        if (data.csrf_token) csrfToken = data.csrf_token;

        totalPages = data.pagination?.pages || 1;
        renderToolbar('search', searchInfo(data.pagination?.total || 0, 'Thema', 'Themen'));
        renderTopics(data.topics || [], null, true);
    } catch (e) {
        console.error('Error searching forum:', e);
        renderToolbar('search');
        document.getElementById('forum-content').innerHTML = `
            <div class="empty-state">
                <div class="empty-state-icon">❌</div>
                <p>Fehler bei der Suche</p>
            </div>`;
        document.getElementById('pagination-container').innerHTML = '';
    }
}

/**
 * Check if user is logged in
 */
async function checkAuth() {
    try {
        const res = await fetch(`${API_BASE}/check-auth.php`, {
            credentials: 'include'
        });
        const data = await res.json();

        if (data.member || data.authenticated) {
            currentUser = {
                id: data.user_id,
                username: data.username,
                displayName: data.display_name,
                role: data.role
            };
            csrfToken = data.csrf_token;
            updateUserStatus();
        } else {
            currentUser = null;
            csrfToken = data.csrf_token;
            updateUserStatus();
        }
    } catch (e) {
        console.error('Auth check failed:', e);
    }
}

/**
 * Update user status display in header
 */
function updateUserStatus() {
    const el = document.getElementById('user-status');
    if (!el) return;

    if (currentUser) {
        el.innerHTML = `
            <span style="color: var(--color-text-muted); margin-right: var(--spacing-sm);">
                Hallo, <strong>${escapeHtml(currentUser.displayName || currentUser.username)}</strong>
            </span>
            <a href="konto.html" class="btn btn-secondary" style="font-size: 0.85rem;">Mein Konto</a>
            ${['editor', 'admin'].includes(currentUser.role) ? '<a href="admin/index.html" class="btn btn-secondary" style="font-size: 0.85rem;">Admin</a>' : ''}
        `;
        document.getElementById('new-topic-btn').style.display = 'inline-flex';
    } else {
        el.innerHTML = `<a href="konto.html?next=forum.html" class="btn btn-primary">Anmelden</a>
            <a href="konto.html?tab=register" class="btn btn-secondary" style="font-size: 0.85rem;">Registrieren</a>`;
        document.getElementById('new-topic-btn').style.display = 'none';
    }
}

// ============================================
// CATEGORIES VIEW
// ============================================

/**
 * Load all forum categories
 */
async function loadCategories() {
    currentView = 'categories';
    currentCategoryId = null;
    currentTopicId = null;

    updateBreadcrumb([]);
    document.getElementById('forum-title').innerHTML = 'Community <span>Forum</span>';
    document.getElementById('new-topic-btn').style.display = 'none';
    renderToolbar('categories');

    try {
        const res = await fetch(`${API_BASE}/forum-categories.php`);
        const data = await res.json();

        if (!data.success) {
            throw new Error(data.error || 'Fehler beim Laden');
        }

        // Update stats
        if (data.stats) {
            document.getElementById('stat-categories').textContent = data.stats.categories || 0;
            document.getElementById('stat-topics').textContent = data.stats.topics || 0;
            document.getElementById('stat-posts').textContent = data.stats.posts || 0;
        }

        // Update CSRF token
        if (data.csrf_token) {
            csrfToken = data.csrf_token;
        }

        renderCategories(data.categories || []);

    } catch (e) {
        console.error('Error loading categories:', e);
        document.getElementById('forum-content').innerHTML = `
            <div class="empty-state">
                <div class="empty-state-icon">❌</div>
                <p>Fehler beim Laden der Kategorien</p>
                <button class="btn btn-secondary" onclick="loadCategories()">Erneut versuchen</button>
            </div>
        `;
    }
}

/**
 * Render categories list
 */
function renderCategories(categories) {
    const container = document.getElementById('forum-content');

    if (categories.length === 0) {
        container.innerHTML = `
            <div class="empty-state">
                <div class="empty-state-icon">📁</div>
                <p>Noch keine Kategorien vorhanden</p>
            </div>
        `;
        return;
    }

    container.innerHTML = categories.map(cat => `
        <div class="category-card" onclick="navigateTo('category', ${cat.id})">
            <div class="category-header">
                <div class="category-info">
                    <div class="category-name">${escapeHtml(cat.name)}</div>
                    ${cat.description ? `<div class="category-description">${escapeHtml(cat.description)}</div>` : ''}
                </div>
                <div class="category-stats">
                    <div class="category-stat">
                        <span class="category-stat-value">${cat.topic_count || 0}</span>
                        <span class="category-stat-label">Themen</span>
                    </div>
                    <div class="category-stat">
                        <span class="category-stat-value">${cat.post_count || 0}</span>
                        <span class="category-stat-label">Beiträge</span>
                    </div>
                </div>
            </div>
        </div>
    `).join('');

    document.getElementById('pagination-container').innerHTML = '';
}

// ============================================
// CATEGORY/TOPICS VIEW
// ============================================

/**
 * Load topics of a category
 */
async function loadCategory(categoryId) {
    currentView = 'topics';
    currentCategoryId = categoryId;
    currentTopicId = null;

    if (currentUser) {
        document.getElementById('new-topic-btn').style.display = 'inline-flex';
    }

    try {
        const q = searchQuery ? `&q=${encodeURIComponent(searchQuery)}` : '';
        const res = await fetch(`${API_BASE}/forum-topics.php?category=${categoryId}&sort=${topicSort}${q}&limit=20&offset=${(currentPage - 1) * 20}`);
        const data = await res.json();

        if (!data.success) {
            throw new Error(data.error || 'Fehler beim Laden');
        }

        // Update CSRF token
        if (data.csrf_token) {
            csrfToken = data.csrf_token;
        }

        // Update breadcrumb and title
        const categoryName = data.category?.name || 'Kategorie';
        updateBreadcrumb([
            { label: categoryName, url: `?category=${categoryId}` }
        ]);
        document.getElementById('forum-title').innerHTML = `${escapeHtml(categoryName)}`;

        // Pagination
        totalPages = data.pagination?.pages || 1;

        renderToolbar('topics', searchInfo(data.pagination?.total || 0, 'Thema', 'Themen'));
        renderTopics(data.topics || [], data.category);

    } catch (e) {
        console.error('Error loading category:', e);
        document.getElementById('forum-content').innerHTML = `
            <div class="empty-state">
                <div class="empty-state-icon">❌</div>
                <p>Fehler beim Laden der Themen</p>
                <button class="btn btn-secondary" onclick="loadCategory(${categoryId})">Erneut versuchen</button>
            </div>
        `;
    }
}

/**
 * Render topics list
 */
function renderTopics(topics, category, isGlobalSearch = false) {
    const container = document.getElementById('forum-content');

    if (topics.length === 0 && searchQuery) {
        container.innerHTML = `
            <div class="empty-state">
                <div class="empty-state-icon">🔍</div>
                <p>Keine Themen gefunden. Tipp: weniger oder kürzere Suchwörter verwenden.</p>
                <button class="btn btn-secondary" onclick="setSearch('')">Suche aufheben</button>
            </div>
        `;
        document.getElementById('pagination-container').innerHTML = '';
        return;
    }

    if (topics.length === 0) {
        container.innerHTML = `
            <div class="empty-state">
                <div class="empty-state-icon">💬</div>
                <p>Noch keine Themen in dieser Kategorie</p>
                ${currentUser ? `<button class="btn btn-primary" onclick="showNewTopicForm()">Erstes Thema erstellen</button>` : ''}
            </div>
        `;
        document.getElementById('pagination-container').innerHTML = '';
        return;
    }

    container.innerHTML = `
        <div class="topic-list">
            ${topics.map(topic => {
                let iconClass = '';
                let icon = '💬';
                if (topic.is_sticky) {
                    iconClass = 'sticky';
                    icon = '📌';
                }
                if (topic.is_locked) {
                    iconClass = 'locked';
                    icon = '🔒';
                }

                // Bei Suche mit Treffern in Beitraegen: Thema gefiltert auf diese Beitraege oeffnen
                const hits = parseInt(topic.hits) || 0;
                const href = `?topic=${parseInt(topic.id)}` + (searchQuery && hits ? `&q=${encodeURIComponent(searchQuery)}` : '')
                    + (topic.unread && !(searchQuery && hits) ? '&unread=1' : '');
                const where = isGlobalSearch && topic.category_name ? ` • in ${escapeHtml(topic.category_name)}` : '';
                const hitText = searchQuery
                    ? (hits ? ` • <span class="topic-hits">${hits} Treffer in Beiträgen</span>` : ' • <span class="topic-hits">Treffer im Titel</span>')
                    : '';
                const lastText = (topicSort === 'activity' || searchQuery) && topic.last_post_at
                    ? ` • letzte Antwort ${formatDate(topic.last_post_at)}` : '';

                return `
                    <div class="topic-row${topic.unread ? ' unread' : ''}" onclick="openTopicLink(this.querySelector('a.topic-title').getAttribute('href'))">
                        <div class="topic-icon ${iconClass}">${icon}</div>
                        <div class="topic-info">
                            <a href="${escapeAttr(href)}" class="topic-title" onclick="event.stopPropagation(); event.preventDefault(); openTopicLink(this.getAttribute('href'))">
                                ${topic.unread ? '<span class="new-badge" title="Neue Beiträge seit deinem letzten Besuch">Neu</span> ' : ''}${escapeHtml(topic.title)}
                            </a>
                            <div class="topic-meta">
                                von <strong>${escapeHtml(topic.author_display_name || topic.author_name || 'Unbekannt')}</strong>
                                • ${formatDate(topic.created_at)}${lastText}${where}${hitText}
                            </div>
                        </div>
                        <div class="topic-stats">
                            <div class="topic-stat">
                                <div class="topic-stat-value">${topic.post_count || 0}</div>
                                <div class="topic-stat-label">Antworten</div>
                            </div>
                            <div class="topic-stat">
                                <div class="topic-stat-value">${topic.view_count || 0}</div>
                                <div class="topic-stat-label">Aufrufe</div>
                            </div>
                        </div>
                    </div>
                `;
            }).join('')}
        </div>
    `;

    renderPagination();
}

// ============================================
// TOPIC/POSTS VIEW
// ============================================

/**
 * Load a single topic with its posts
 */
async function loadTopic(topicId) {
    currentView = 'topic';
    currentTopicId = topicId;
    document.getElementById('new-topic-btn').style.display = 'none';

    try {
        const q = searchQuery ? `&q=${encodeURIComponent(searchQuery)}` : '';
        const unreadJump = new URLSearchParams(window.location.search).get('unread') === '1';
        const res = await fetch(`${API_BASE}/forum-topics.php?id=${topicId}&sort=${postSort}${q}&limit=20&offset=${(currentPage - 1) * 20}${unreadJump ? '&unread=1' : ''}`,
            { credentials: 'include', cache: 'no-store' });
        const data = await res.json();

        if (!data.success) {
            throw new Error(data.error || 'Fehler beim Laden');
        }

        // Update CSRF token
        if (data.csrf_token) {
            csrfToken = data.csrf_token;
        }

        currentCategoryId = data.topic?.category_id;

        // Update breadcrumb and title
        const topic = data.topic;
        updateBreadcrumb([
            { label: topic.category_name || 'Kategorie', url: `?category=${topic.category_id}` },
            { label: topic.title, url: `?topic=${topicId}` }
        ]);
        document.getElementById('forum-title').innerHTML = escapeHtml(topic.title);

        // Pagination
        totalPages = data.pagination?.pages || 1;
        allPostsInTopic = data.all_posts || 0;
        if (data.jumped && data.page) {
            currentPage = data.page;
        }
        if (unreadJump) {
            // unread nur einmal auswerten; Seite in der Adresse festhalten
            const p = new URLSearchParams(window.location.search);
            p.delete('unread');
            if (currentPage > 1) p.set('page', currentPage); else p.delete('page');
            history.replaceState({}, '', 'forum.html?' + p.toString());
        }
        readSince = data.read_since || null;
        topicSubscribed = !!data.subscribed;
        canReplyHere = !!data.permissions?.can_reply;

        renderToolbar('topic', searchInfo(data.pagination?.total || 0, 'Beitrag', 'Beiträge'));
        renderPosts(data.posts || [], data.topic, data.first_post);
        if (unreadJump) scrollToFirstNew();

    } catch (e) {
        console.error('Error loading topic:', e);
        document.getElementById('forum-content').innerHTML = `
            <div class="empty-state">
                <div class="empty-state-icon">❌</div>
                <p>Fehler beim Laden des Themas</p>
                <button class="btn btn-secondary" onclick="loadTopic(${topicId})">Erneut versuchen</button>
            </div>
        `;
    }
}

/**
 * Profilbild oder Anfangsbuchstabe (nur Pfade, die der Server vergibt)
 */
function avatarHtml(path, initial) {
    const ok = typeof path === 'string' && /^img\/(avatars\/(legacy\/)?[A-Za-z0-9_-]+\.(webp|jpg|png|gif)|teams\/[A-Za-z0-9]+\.gif)$/.test(path);
    return ok ? `<img src="${path}"${path.startsWith('img/teams/') ? ' class="is-crest"' : ''} alt="" loading="lazy" onerror="this.replaceWith(document.createTextNode('${initial.replace(/[^\p{L}\p{N}]/gu, '')}'))">` : escapeHtml(initial);
}

/**
 * Render posts list
 */
function renderPosts(posts, topic, firstPost = null) {
    const container = document.getElementById('forum-content');

    postCache = {};
    const renderPost = (post, pinned = false) => {
        postCache[post.id] = post;
        const isFirst = post.is_first_post;
        const authorInitial = (post.author_display_name || post.author_name || 'U').charAt(0).toUpperCase();
        const canEdit = currentUser && (currentUser.id === post.user_id || currentUser.role === 'admin');
        const isNew = !!(readSince && currentUser && post.created_at > readSince && post.user_id !== currentUser.id);
        const canQuote = currentUser && !topic.is_locked;
        const canReport = currentUser && post.user_id !== currentUser.id;
        // Loeschen: Admin/Redaktion immer, eigene Beitraege innerhalb 1 Stunde (Thema nur ohne Antworten)
        const isStaff = currentUser && ['admin', 'editor'].includes(currentUser.role);
        const ownRecent = currentUser && post.user_id === currentUser.id && (Date.now() - parseDbDate(post.created_at).getTime()) < 3600000;
        const canDelete = isStaff || (ownRecent && (!isFirst || allPostsInTopic <= 1));

        return `
            <div class="post-card ${isFirst ? 'post-first' : ''}${isNew ? ' post-new' : ''}" id="post-${parseInt(post.id)}" data-created="${escapeAttr(post.created_at || '')}">
                <div class="post-header">
                    <div class="post-author">
                        <div class="post-avatar">${avatarHtml(post.author_avatar, authorInitial)}</div>
                        <div>
                            <div class="post-author-name">${escapeHtml(post.author_display_name || post.author_name || 'Unbekannt')}</div>
                            <div class="post-date">${formatDateTime(post.created_at)}</div>
                        </div>
                    </div>
                    <span style="color: var(--color-text-muted); font-size: 0.85rem;">${isNew ? '<span class="new-badge">Neu</span> ' : ''}${pinned ? '📌 Eröffnungsbeitrag · ' : ''}#${parseInt(post.post_nr) || ''}</span>
                </div>
                <div class="post-content" id="post-content-${parseInt(post.id)}">
                    ${formatPostContent(post.content)}
                    ${post.edited_at ? `<div class="post-edited">Bearbeitet am ${formatDateTime(post.edited_at)}${post.editor_name ? ` von ${escapeHtml(post.editor_name)}` : ''}</div>` : ''}
                </div>
                ${canQuote || canEdit || canReport || canDelete ? `
                    <div class="post-footer">
                        ${canReport ? `<button class="btn btn-ghost post-report" onclick="reportPost(${post.id})" title="Beitrag an die Moderation melden">⚑ Melden</button>` : ''}
                        ${canQuote ? `<button class="btn btn-ghost" onclick="quotePost(${post.id})" style="font-size: 0.85rem;">❝ Zitieren</button>` : ''}
                        ${canEdit ? `<button class="btn btn-ghost" onclick="editPost(${post.id})" style="font-size: 0.85rem;">Bearbeiten</button>` : ''}
                        ${canDelete ? `<button class="btn btn-ghost" onclick="deletePost(${post.id}, ${isFirst ? 'true' : 'false'})" style="font-size: 0.85rem; color: var(--color-primary);">${isFirst ? 'Thema löschen' : 'Löschen'}</button>` : ''}
                    </div>
                ` : ''}
            </div>
        `;
    };

    if (posts.length === 0 && !firstPost) {
        container.innerHTML = searchQuery ? `
            <div class="empty-state">
                <div class="empty-state-icon">🔍</div>
                <p>Kein Beitrag in diesem Thema enthält alle Suchwörter.</p>
                <button class="btn btn-secondary" onclick="setSearch('')">Suche aufheben</button>
            </div>` : `
            <div class="empty-state"><p>Noch keine Beiträge.</p></div>`;
        document.getElementById('pagination-container').innerHTML = '';
        return;
    }

    let replyHtml = '';
    // Reply form (if topic not locked)
    if (!topic.is_locked) {
        if (currentUser) {
            replyHtml = `
                <div class="reply-form" id="reply-form">
                    <h3>Antwort schreiben</h3>
                    ${editorHtml('reply-content', 'Deine Antwort...')}
                    <div class="reply-actions">
                        <button class="btn btn-primary" onclick="submitReply()">Antworten</button>
                        ${topicSubscribed ? '' : `<label class="sub-check"><input type="checkbox" id="reply-subscribe" checked> 🔔 Bei neuen Antworten per E-Mail benachrichtigen</label>`}
                    </div>
                </div>
            `;
        } else {
            replyHtml = `
                <div class="login-prompt">
                    <p>Du musst eingeloggt sein, um zu antworten.</p>
                    <a href="konto.html?next=forum.html" class="btn btn-primary">Jetzt anmelden</a>
                    <a href="konto.html?tab=register" class="btn btn-secondary">Registrieren</a>
                </div>
            `;
        }
    } else {
        replyHtml = `
            <div class="login-prompt">
                <p>🔒 Dieses Thema ist geschlossen und kann nicht mehr beantwortet werden.</p>
            </div>
        `;
    }

    // Neueste zuerst: Eroeffnungsbeitrag oben, Antwortfeld direkt darunter (nur Seite 1),
    // sonst wie gewohnt am Ende der Seite
    const replyOnTop = postSort === 'new' && !searchQuery;
    let html = currentUser ? subscribeBarHtml() : '';
    if (firstPost) html += renderPost(firstPost, true);
    if (replyOnTop && currentPage === 1) html += replyHtml;
    html += posts.map(p => renderPost(p)).join('');
    if (!replyOnTop) html += replyHtml;

    container.innerHTML = html;
    if (searchQuery) highlightMatches(container, searchQuery);
    renderPagination();
    restoreDraft('reply-content');
}

// ============================================
// FORMS & ACTIONS
// ============================================

/**
 * Show new topic form
 */
function showNewTopicForm() {
    if (!currentUser) {
        alert('Bitte melde dich an, um ein Thema zu erstellen.');
        return;
    }

    const container = document.getElementById('forum-content');
    const existingForm = document.querySelector('.new-topic-form');

    if (existingForm) {
        existingForm.remove();
        return;
    }

    // If we're in topics view, pre-select the current category
    const categorySelect = currentCategoryId
        ? `<input type="hidden" id="topic-category" value="${currentCategoryId}">`
        : `<div class="form-group">
               <label>Kategorie</label>
               <select id="topic-category" required></select>
           </div>`;

    const formHtml = `
        <div class="new-topic-form">
            <h3 style="margin-bottom: var(--spacing-lg);">Neues Thema erstellen</h3>
            ${categorySelect}
            <div class="form-group">
                <label>Titel</label>
                <input type="text" id="topic-title" placeholder="Thema des Beitrags..." required maxlength="200">
            </div>
            <div class="form-group">
                <label>Inhalt</label>
                ${editorHtml('topic-content', 'Dein Beitrag...', 200)}
                <label class="sub-check"><input type="checkbox" id="topic-subscribe" checked> 🔔 Bei Antworten per E-Mail benachrichtigen</label>
            </div>
            <div style="display: flex; gap: var(--spacing-md);">
                <button class="btn btn-primary" onclick="submitNewTopic()">Thema erstellen</button>
                <button class="btn btn-secondary" onclick="hideNewTopicForm()">Abbrechen</button>
            </div>
        </div>
    `;

    container.insertAdjacentHTML('afterbegin', formHtml);

    // Load categories if needed
    if (!currentCategoryId) {
        loadCategorySelect();
    }

    restoreDraft('topic-content');
    // Focus title input
    document.getElementById('topic-title').focus();
}

/**
 * Hide new topic form
 */
function hideNewTopicForm() {
    const form = document.querySelector('.new-topic-form');
    if (form) {
        form.remove();
    }
}

/**
 * Load categories for select dropdown
 */
async function loadCategorySelect() {
    const select = document.getElementById('topic-category');
    if (!select || select.tagName !== 'SELECT') return;

    try {
        const res = await fetch(`${API_BASE}/forum-categories.php`);
        const data = await res.json();

        if (data.categories) {
            select.innerHTML = data.categories.map(cat =>
                `<option value="${cat.id}">${escapeHtml(cat.name)}</option>`
            ).join('');
        }
    } catch (e) {
        console.error('Error loading categories:', e);
    }
}

/**
 * Submit new topic
 */
async function submitNewTopic() {
    const categoryId = document.getElementById('topic-category').value;
    const title = document.getElementById('topic-title').value.trim();
    const content = document.getElementById('topic-content').value.trim();

    if (!categoryId || !title || !content) {
        alert('Bitte alle Felder ausfüllen.');
        return;
    }

    if (title.length < 3) {
        alert('Der Titel muss mindestens 3 Zeichen haben.');
        return;
    }

    if (content.length < 10) {
        alert('Der Inhalt muss mindestens 10 Zeichen haben.');
        return;
    }

    try {
        const res = await fetch(`${API_BASE}/forum-topics.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({
                category_id: parseInt(categoryId),
                title,
                content,
                subscribe: document.getElementById('topic-subscribe')?.checked !== false,
                csrf_token: csrfToken
            })
        });

        const data = await res.json();

        if (data.success) {
            // Update CSRF token
            if (data.csrf_token) {
                csrfToken = data.csrf_token;
            }
            clearDraft('topic-content');
            // Navigate to the new topic
            navigateTo('topic', data.topic_id);
        } else {
            alert(data.error || 'Fehler beim Erstellen des Themas');
        }
    } catch (e) {
        console.error('Error creating topic:', e);
        alert('Netzwerkfehler beim Erstellen des Themas');
    }
}

/**
 * Submit reply to topic
 */
async function submitReply() {
    const content = document.getElementById('reply-content').value.trim();

    if (!content) {
        alert('Bitte einen Text eingeben.');
        return;
    }

    if (content.length < 3) {
        alert('Die Antwort muss mindestens 3 Zeichen haben.');
        return;
    }

    try {
        const res = await fetch(`${API_BASE}/forum-posts.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({
                action: 'create',
                topic_id: currentTopicId,
                content,
                subscribe: topicSubscribed || document.getElementById('reply-subscribe')?.checked !== false,
                csrf_token: csrfToken
            })
        });

        const data = await res.json();

        if (data.success) {
            // Update CSRF token
            if (data.csrf_token) {
                csrfToken = data.csrf_token;
            }
            clearDraft('reply-content');
            // Neue Antwort zeigen: bei "neueste zuerst" Seite 1, sonst letzte Seite; Suche aufheben
            const page = postSort === 'new' ? 1 : Math.max(1, Math.ceil((allPostsInTopic + 1) / 20));
            updateUrl({ q: null, page });
        } else {
            alert(data.error || 'Fehler beim Posten der Antwort');
        }
    } catch (e) {
        console.error('Error posting reply:', e);
        alert('Netzwerkfehler beim Posten der Antwort');
    }
}

/**
 * Edit a post
 */
async function editPost(postId) {
    const post = postCache[postId];
    const box = document.getElementById('post-content-' + postId);
    if (!post || !box || box.querySelector('.bb-editor')) return;
    const id = 'edit-' + postId;
    box.innerHTML = editorHtml(id, '', 160) + `
        <div class="reply-actions">
            <button class="btn btn-primary" onclick="saveEdit(${postId})">Speichern</button>
            <button class="btn btn-secondary" onclick="loadTopic(currentTopicId)">Abbrechen</button>
        </div>`;
    const ta = document.getElementById(id);
    ta.value = post.content || '';
    ta.focus();
}

async function saveEdit(postId) {
    const content = (document.getElementById('edit-' + postId)?.value || '').trim();
    if (content.length < 3) {
        alert('Der Text muss mindestens 3 Zeichen haben.');
        return;
    }

    try {
        const res = await fetch(`${API_BASE}/forum-posts.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({
                action: 'edit',
                post_id: postId,
                content,
                csrf_token: csrfToken
            })
        });

        const data = await res.json();

        if (data.success) {
            if (data.csrf_token) {
                csrfToken = data.csrf_token;
            }
            loadTopic(currentTopicId);
        } else {
            alert(data.error || 'Fehler beim Bearbeiten');
        }
    } catch (e) {
        console.error('Error editing post:', e);
        alert('Netzwerkfehler beim Bearbeiten');
    }
}

/**
 * Delete a post
 */
async function deletePost(postId, isFirst = false) {
    if (!confirm(isFirst
        ? `Ganzes Thema mit allen ${allPostsInTopic > 1 ? allPostsInTopic + ' Beiträgen' : 'Beiträgen'} endgültig löschen?`
        : 'Beitrag wirklich löschen?')) return;

    try {
        const res = await fetch(`${API_BASE}/forum-posts.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({
                action: 'delete',
                post_id: postId,
                csrf_token: csrfToken
            })
        });

        const data = await res.json();

        if (data.success) {
            if (data.csrf_token) {
                csrfToken = data.csrf_token;
            }
            if (data.topic_deleted) {
                navigateTo(data.category_id ? 'category' : 'categories', data.category_id || null);
                return;
            }
            loadTopic(currentTopicId);
        } else {
            alert(data.error || 'Fehler beim Löschen');
        }
    } catch (e) {
        console.error('Error deleting post:', e);
        alert('Netzwerkfehler beim Löschen');
    }
}

// ============================================
// KOMFORT: Editor, Zitat, Ungelesen, Abo, Entwurf
// ============================================

let postCache = {};
let readSince = null;
let topicSubscribed = false;
let canReplyHere = false;

const SMILEYS = ['😀', '😂', '😉', '😍', '😎', '🤔', '😮', '😢', '😡', '🙈', '👍', '👎', '👏', '🙏', '💪', '⚽', '🥅', '🏆', '🔥', '🍺', '❤️', '🟨', '🟥', '🤝'];

/** Textfeld mit Formatierungsleiste, Smileys und Vorschau */
function editorHtml(id, placeholder = '', minHeight = 120) {
    const b = (label, title, action) => `<button type="button" class="bb-btn" title="${title}" onmousedown="event.preventDefault()" onclick="${action}">${label}</button>`;
    return `
        <div class="bb-editor" data-for="${id}">
            <div class="bb-toolbar" role="toolbar" aria-label="Formatierung">
                ${b('<b>B</b>', 'Fett', `bbWrap('${id}','[b]','[/b]')`)}
                ${b('<i>I</i>', 'Kursiv', `bbWrap('${id}','[i]','[/i]')`)}
                ${b('<u>U</u>', 'Unterstrichen', `bbWrap('${id}','[u]','[/u]')`)}
                ${b('<s>S</s>', 'Durchgestrichen', `bbWrap('${id}','[s]','[/s]')`)}
                ${b('🔗', 'Link', `bbLink('${id}')`)}
                ${b('•≡', 'Liste', `bbList('${id}')`)}
                ${b('❝', 'Zitat', `bbWrap('${id}','[quote]','[/quote]')`)}
                ${b('🖼 Bild', 'Bild hochladen (auch per Einfügen oder Hineinziehen)', `document.getElementById('${id}-file').click()`)}
                <input type="file" id="${id}-file" accept="image/jpeg,image/png,image/webp,image/gif" hidden onchange="bbUploadFiles('${id}', this.files); this.value=''">
                <span class="bb-status" id="${id}-status"></span>
                <span class="bb-sep"></span>
                <span class="bb-smileys">${SMILEYS.map(e => `<button type="button" class="bb-smiley" onmousedown="event.preventDefault()" onclick="bbInsert('${id}','${e}')" title="${e}">${e}</button>`).join('')}</span>
                <button type="button" class="bb-btn bb-preview-btn" onclick="bbPreview('${id}', this)">Vorschau</button>
            </div>
            <textarea id="${id}" placeholder="${escapeAttr(placeholder)}" style="min-height:${parseInt(minHeight)}px" oninput="saveDraft('${id}')"
                onpaste="bbPaste(event, '${id}')" ondragover="event.preventDefault()" ondrop="bbDrop(event, '${id}')"></textarea>
            <div class="bb-preview post-content" id="${id}-preview" hidden></div>
        </div>`;
}

/** Bilder hochladen und als [img]…[/img] einfuegen */
async function bbUploadFiles(id, files) {
    const list = [...(files || [])].filter(f => /^image\/(jpeg|png|webp|gif)$/.test(f.type));
    if (!list.length) return;
    const status = document.getElementById(id + '-status');
    for (const f of list.slice(0, 5)) {
        if (f.size > 8 * 1048576) { alert(`„${f.name}“ ist größer als 8 MB.`); continue; }
        if (status) status.textContent = 'Bild wird hochgeladen …';
        const fd = new FormData();
        fd.append('file', f);
        fd.append('csrf_token', csrfToken || '');
        try {
            const res = await fetch(`${API_BASE}/forum-image.php`, { method: 'POST', credentials: 'include', body: fd });
            const data = await res.json();
            if (data.csrf_token) csrfToken = data.csrf_token;
            if (data.success) bbInsert(id, `\n[img]${data.path}[/img]\n`);
            else alert(data.error || 'Upload fehlgeschlagen');
        } catch (e) {
            alert('Netzwerkfehler beim Hochladen');
        }
    }
    if (status) status.textContent = '';
}

function bbPaste(e, id) {
    const files = [...(e.clipboardData?.files || [])];
    if (files.some(f => f.type.startsWith('image/'))) {
        e.preventDefault();
        bbUploadFiles(id, files);
    }
}

function bbDrop(e, id) {
    const files = [...(e.dataTransfer?.files || [])];
    if (files.length) {
        e.preventDefault();
        bbUploadFiles(id, files);
    }
}

/** Beitrag an die Moderation melden */
async function reportPost(postId) {
    const reason = prompt('Was ist an diesem Beitrag nicht in Ordnung? (optional)', '');
    if (reason === null) return;
    try {
        const res = await fetch(`${API_BASE}/forum-posts.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({ action: 'report', post_id: postId, reason, csrf_token: csrfToken })
        });
        const data = await res.json();
        if (data.csrf_token) csrfToken = data.csrf_token;
        alert(data.success ? data.message : (data.error || 'Melden fehlgeschlagen'));
    } catch (e) {
        alert('Netzwerkfehler beim Melden');
    }
}

function bbField(id) {
    return document.getElementById(id);
}

function bbWrap(id, open, close) {
    const ta = bbField(id);
    if (!ta) return;
    const { selectionStart: a, selectionEnd: e, value: v } = ta;
    const sel = v.slice(a, e);
    ta.value = v.slice(0, a) + open + sel + close + v.slice(e);
    const pos = sel ? a + open.length + sel.length + close.length : a + open.length;
    ta.focus();
    ta.setSelectionRange(pos, pos);
    saveDraft(id);
}

function bbInsert(id, text) {
    const ta = bbField(id);
    if (!ta) return;
    const { selectionStart: a, selectionEnd: e, value: v } = ta;
    ta.value = v.slice(0, a) + text + v.slice(e);
    ta.focus();
    ta.setSelectionRange(a + text.length, a + text.length);
    saveDraft(id);
}

function bbLink(id) {
    const ta = bbField(id);
    if (!ta) return;
    const url = prompt('Adresse des Links (https://…):', 'https://');
    if (!url || !/^https?:\/\/\S+$/i.test(url.trim())) return;
    const sel = ta.value.slice(ta.selectionStart, ta.selectionEnd);
    bbWrap(id, `[url=${url.trim()}]`, sel ? '[/url]' : 'Linktext[/url]');
}

function bbList(id) {
    const ta = bbField(id);
    if (!ta) return;
    const sel = ta.value.slice(ta.selectionStart, ta.selectionEnd);
    const items = sel ? sel.split(/\n/).filter(l => l.trim()).map(l => '[*]' + l.trim()).join('\n') : '[*]';
    bbWrap(id, '[list]\n' + items + '\n', '[/list]');
}

function bbPreview(id, btn) {
    const ta = bbField(id);
    const pv = document.getElementById(id + '-preview');
    if (!ta || !pv) return;
    const show = pv.hidden;
    pv.hidden = !show;
    ta.hidden = show;
    btn.textContent = show ? 'Bearbeiten' : 'Vorschau';
    btn.classList.toggle('active', show);
    if (show) pv.innerHTML = formatPostContent(ta.value) || '<p style="color:var(--color-text-muted)">Noch kein Text.</p>';
}

/**
 * BBCode fuer Beitraege ohne HTML: [b] [i] [u] [s] [url] [url=…] [list][*] [quote] [quote=Name]
 * Zuerst wird alles maskiert; erzeugt werden nur feste, sichere Tags (Links nur http/https).
 */
function renderBBCode(content) {
    return bbBlock(escapeHtml(String(content).replace(/\r\n?/g, '\n')), 0);
}

/** Zitate (auch verschachtelt) als Bloecke, dazwischen normaler Text */
function bbBlock(t, depth) {
    const openRe = /\[quote(?:=([^\]\n]{1,60}))?\]/gi;
    let out = '';
    let i = 0;
    while (true) {
        openRe.lastIndex = i;
        const m = openRe.exec(t);
        if (!m) { out += bbInline(t.slice(i)); break; }
        // passendes [/quote] suchen (Verschachtelung zaehlen)
        const tagRe = /\[quote(?:=[^\]\n]{1,60})?\]|\[\/quote\]/gi;
        tagRe.lastIndex = m.index + m[0].length;
        let level = 1, close = null, tm;
        while ((tm = tagRe.exec(t))) {
            level += tm[0].toLowerCase() === '[/quote]' ? -1 : 1;
            if (level === 0) { close = tm; break; }
        }
        if (!close) { out += bbInline(t.slice(i)); break; }
        out += bbInline(t.slice(i, m.index));
        const inner = t.slice(m.index + m[0].length, close.index).replace(/^\n+|\n+$/g, '');
        out += `<blockquote class="forum-quote">${m[1] ? `<div class="quote-header">${m[1]} schrieb:</div>` : ''}`
            + `<div class="quote-body">${depth < 4 ? bbBlock(inner, depth + 1) : bbInline(inner)}</div></blockquote>`;
        i = close.index + close[0].length;
        if (t[i] === '\n') i++;
    }
    return out;
}

function bbInline(t) {
    const safeUrl = u => /^https?:\/\/[^\s"'<>]+$/i.test(u) ? u : null;
    const link = (url, txt) => {
        const u = safeUrl(url.replace(/&amp;/g, '&'));
        return u ? `<a href="${escapeAttr(u)}" target="_blank" rel="noopener noreferrer nofollow">${txt}</a>` : null;
    };
    // Bilder nur aus dem eigenen Upload-Ordner
    t = t.replace(/\[img\](img\/forum\/[a-f0-9]{24}\.(?:webp|jpg))\[\/img\]/gi, (m, src) =>
        `<a href="${src}" target="_blank" rel="noopener" class="post-img-link"><img src="${src}" class="post-img" alt="Bild" loading="lazy"></a>`);
    t = t.replace(/\[list\]\n?([\s\S]*?)\[\/list\]\n?/gi, (m, body) =>
        '\u0001<ul>' + body.split(/\[\*\]/).map(x => x.trim()).filter(Boolean).map(x => `<li>${x.replace(/\n/g, '<br>')}</li>`).join('') + '</ul>\u0001');
    for (let k = 0; k < 3; k++) {
        t = t.replace(/\[(b|i|u|s)\]([\s\S]*?)\[\/\1\]/gi, (m, tag, body) => `<${tag.toLowerCase()}>${body}</${tag.toLowerCase()}>`);
    }
    t = t.replace(/\[url=([^\]\s]+)\]([\s\S]*?)\[\/url\]/gi, (m, url, txt) => link(url, txt) || m);
    t = t.replace(/\[url\]([^\[\s]+)\[\/url\]/gi, (m, url) => link(url, url) || m);
    // Absaetze; Listen bleiben eigene Bloecke
    return t.split('\u0001').map((part, idx) => idx % 2
        ? part
        : part.split(/\n\n+/).map(p => p.trim() ? `<p>${p.trim().replace(/\n/g, '<br>')}</p>` : '').join('')).join('');
}

/** Beitrag als Zitat ins Antwortfeld uebernehmen */
function quotePost(postId) {
    const post = postCache[postId];
    const ta = document.getElementById('reply-content');
    if (!post) return;
    if (!ta) {
        alert('Zum Zitieren bitte anmelden – oder das Thema ist geschlossen.');
        return;
    }
    let text = String(post.content || '');
    if (/<[a-z][\s\S]*>/i.test(text)) {
        const d = new DOMParser().parseFromString(text.replace(/<br\s*\/?>/gi, '\n').replace(/<\/p>/gi, '\n\n'), 'text/html');
        d.querySelectorAll('blockquote, .forum-quote').forEach(q => q.remove());
        text = d.body.textContent || '';
    }
    text = text.replace(/\[quote(?:=[^\]]*)?\][\s\S]*?\[\/quote\]\n?/gi, '').trim();
    if (text.length > 1500) text = text.slice(0, 1500) + ' …';
    const who = (post.author_display_name || post.author_name || post.guest_name || '').replace(/[\[\]\n]/g, '').slice(0, 60);
    const block = `[quote${who ? '=' + who : ''}]${text}[/quote]\n`;
    ta.value = (ta.value.trim() ? ta.value.replace(/\s*$/, '\n\n') : '') + block;
    saveDraft('reply-content');
    ta.scrollIntoView({ behavior: 'smooth', block: 'center' });
    ta.focus();
    ta.setSelectionRange(ta.value.length, ta.value.length);
}

/** Erster ungelesener Beitrag (chronologisch) in den Blick */
function scrollToFirstNew() {
    const news = [...document.querySelectorAll('.post-card.post-new')]
        .sort((a, b) => (a.dataset.created || '').localeCompare(b.dataset.created || ''));
    if (news.length) setTimeout(() => news[0].scrollIntoView({ behavior: 'smooth', block: 'start' }), 50);
}

function subscribeBarHtml() {
    return `
        <div class="sub-bar" id="sub-bar">
            ${topicSubscribed
                ? `<span>🔔 Du bekommst eine E-Mail bei neuen Antworten.</span><button class="btn btn-ghost" onclick="toggleSubscribe(false)">Abbestellen</button>`
                : `<span>🔕 Keine E-Mails zu diesem Thema.</span><button class="btn btn-ghost" onclick="toggleSubscribe(true)">🔔 Abonnieren</button>`}
        </div>`;
}

async function toggleSubscribe(on) {
    const data = await forumAction({ action: on ? 'subscribe' : 'unsubscribe', topic_id: currentTopicId });
    if (!data) return;
    topicSubscribed = !!data.subscribed;
    const bar = document.getElementById('sub-bar');
    if (bar) bar.outerHTML = subscribeBarHtml();
    const chk = document.getElementById('reply-subscribe');
    if (chk) chk.closest('label').style.display = topicSubscribed ? 'none' : '';
}

async function markAllRead() {
    const data = await forumAction({ action: 'mark_all_read' });
    if (!data) return;
    document.querySelectorAll('.topic-row.unread').forEach(r => r.classList.remove('unread'));
    document.querySelectorAll('.topic-row .new-badge').forEach(b => b.remove());
    document.querySelectorAll('.topic-row a.topic-title').forEach(a => a.setAttribute('href', a.getAttribute('href').replace('&unread=1', '')));
}

async function forumAction(body) {
    try {
        const res = await fetch(`${API_BASE}/forum-topics.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({ ...body, csrf_token: csrfToken })
        });
        const data = await res.json();
        if (data.csrf_token) csrfToken = data.csrf_token;
        if (!data.success) { alert(data.error || 'Fehler'); return null; }
        return data;
    } catch (e) {
        alert('Netzwerkfehler');
        return null;
    }
}

/** Entwurf im Browser sichern, damit eine angefangene Antwort nicht verloren geht */
function draftKey(id) {
    return 'hafo_draft_' + (id === 'reply-content' ? 'topic_' + currentTopicId : id === 'topic-content' ? 'new_' + (currentCategoryId || 0) : id);
}
let draftTimer = null;
function saveDraft(id) {
    clearTimeout(draftTimer);
    draftTimer = setTimeout(() => {
        const ta = bbField(id);
        if (!ta || id.startsWith('edit-')) return;
        try { ta.value.trim() ? localStorage.setItem(draftKey(id), ta.value) : localStorage.removeItem(draftKey(id)); } catch (e) {}
    }, 400);
}
function restoreDraft(id) {
    const ta = bbField(id);
    if (!ta || ta.value) return;
    try { const v = localStorage.getItem(draftKey(id)); if (v) ta.value = v; } catch (e) {}
}
function clearDraft(id) {
    try { localStorage.removeItem(draftKey(id)); } catch (e) {}
}

// ============================================
// NAVIGATION & UI
// ============================================

/** Link der Form ?topic=ID[&q=...] ohne Seitenneuladen oeffnen */
function openTopicLink(href) {
    history.pushState({}, '', 'forum.html' + href);
    window.scrollTo({ top: 0 });
    parseUrl();
}

/**
 * Navigate to a view
 */
function navigateTo(view, id = null) {
    let url = 'forum.html';

    if (view === 'category' && id) {
        url = `forum.html?category=${id}`;
        currentPage = 1;
    } else if (view === 'topic' && id) {
        url = `forum.html?topic=${id}`;
        currentPage = 1;
    }

    history.pushState({}, '', url);
    parseUrl();
}

/**
 * Update breadcrumb navigation
 */
function updateBreadcrumb(items) {
    const container = document.getElementById('forum-breadcrumb');

    let html = '<a href="forum.html" onclick="event.preventDefault(); navigateTo(\'categories\')">Forum</a>';

    items.forEach((item, index) => {
        html += ' <span>›</span> ';
        if (index === items.length - 1) {
            html += `<span style="color: var(--color-text-primary);">${escapeHtml(item.label)}</span>`;
        } else {
            html += `<a href="${item.url}" onclick="event.preventDefault(); history.pushState({}, '', '${item.url}'); parseUrl();">${escapeHtml(item.label)}</a>`;
        }
    });

    container.innerHTML = html;
}

/**
 * Render pagination controls
 */
function renderPagination() {
    const container = document.getElementById('pagination-container');

    if (totalPages <= 1) {
        container.innerHTML = '';
        return;
    }

    let html = '<div class="pagination">';

    // Previous button
    html += `<button class="pagination-btn" onclick="goToPage(${currentPage - 1})" ${currentPage === 1 ? 'disabled' : ''}>← Zurück</button>`;

    // Page numbers
    const maxVisible = 5;
    let startPage = Math.max(1, currentPage - Math.floor(maxVisible / 2));
    let endPage = Math.min(totalPages, startPage + maxVisible - 1);
    startPage = Math.max(1, endPage - maxVisible + 1);

    if (startPage > 1) {
        html += `<button class="pagination-btn" onclick="goToPage(1)">1</button>`;
        if (startPage > 2) {
            html += '<span style="padding: 0 var(--spacing-sm);">...</span>';
        }
    }

    for (let i = startPage; i <= endPage; i++) {
        html += `<button class="pagination-btn ${i === currentPage ? 'active' : ''}" onclick="goToPage(${i})">${i}</button>`;
    }

    if (endPage < totalPages) {
        if (endPage < totalPages - 1) {
            html += '<span style="padding: 0 var(--spacing-sm);">...</span>';
        }
        html += `<button class="pagination-btn" onclick="goToPage(${totalPages})">${totalPages}</button>`;
    }

    // Next button
    html += `<button class="pagination-btn" onclick="goToPage(${currentPage + 1})" ${currentPage === totalPages ? 'disabled' : ''}>Weiter →</button>`;

    html += '</div>';
    container.innerHTML = html;
}

/**
 * Go to a specific page
 */
function goToPage(page) {
    if (page < 1 || page > totalPages || page === currentPage) return;

    currentPage = page;

    // Update URL
    const params = new URLSearchParams(window.location.search);
    if (page === 1) {
        params.delete('page');
    } else {
        params.set('page', page);
    }
    const newUrl = 'forum.html' + (params.toString() ? '?' + params.toString() : '');
    history.pushState({}, '', newUrl);

    // Reload current view
    if (currentView === 'topics') {
        loadCategory(currentCategoryId);
    } else if (currentView === 'search') {
        loadSearch();
    } else if (currentView === 'topic') {
        loadTopic(currentTopicId);
    }

    // Scroll to top
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

// ============================================
// HELPERS
// ============================================

/**
 * Toggle mobile menu
 */
function toggleMobileMenu() {
    document.getElementById('mobile-menu').classList.toggle('active');
}

/**
 * Escape HTML to prevent XSS
 */
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

/** Fuer Attributwerte in "..." (escapeHtml kodiert keine Anfuehrungszeichen) */
function escapeAttr(text) {
    return String(text ?? '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

/**
 * Format post content (supports sanitized HTML from migrated SMF posts & plain text for new posts)
 */
function formatPostContent(content) {
    if (!content) return '';

    const hasHtml = /<(?:br|p|div|span|strong|b|em|i|u|s|sub|sup|blockquote|ul|ol|li|pre|code|a|img|hr|table|tr|td|th)\b/i.test(content);
    if (!hasHtml) {
        return renderBBCode(content);
    }

    // Sanitize HTML via DOMParser (allow safe formatting tags & attributes only)
    const allowedTags = new Set([
        'BR', 'P', 'DIV', 'SPAN', 'STRONG', 'B', 'EM', 'I', 'U', 'S', 'SUB', 'SUP',
        'BLOCKQUOTE', 'UL', 'OL', 'LI', 'PRE', 'CODE', 'A', 'IMG', 'HR', 'TABLE', 'TBODY', 'THEAD', 'TR', 'TD', 'TH'
    ]);
    const allowedAttrs = {
        'A': new Set(['href', 'target', 'rel']),
        'IMG': new Set(['src', 'alt', 'width', 'height', 'style', 'loading']),
        'SPAN': new Set(['style', 'class']),
        'DIV': new Set(['style', 'class']),
        'BLOCKQUOTE': new Set(['class']),
        'PRE': new Set(['class'])
    };

    const isSafeUrl = (val, name) => {
        try {
            const u = new URL(val, location.href);
            const ok = name === 'href' ? ['http:', 'https:', 'mailto:'] : ['http:', 'https:'];
            return ok.includes(u.protocol);
        } catch (e) {
            return false;
        }
    };

    const doc = new DOMParser().parseFromString(content, 'text/html');
    const cleanNode = (node) => {
        const children = Array.from(node.childNodes);
        for (const child of children) {
            if (child.nodeType === Node.ELEMENT_NODE) {
                const tag = child.tagName.toUpperCase();
                if (!allowedTags.has(tag)) {
                    child.replaceWith(document.createTextNode(child.textContent || ''));
                    continue;
                }
                const allowedForTag = allowedAttrs[tag] || new Set();
                for (const attr of Array.from(child.attributes)) {
                    const name = attr.name.toLowerCase();
                    const val = attr.value.trim();
                    if (!allowedForTag.has(name)) {
                        child.removeAttribute(attr.name);
                    } else if ((name === 'href' || name === 'src') && !isSafeUrl(val, name)) {
                        // Allowlist auf das vom Browser normalisierte Schema
                        // (java&#x09;script: o. ae. umgeht sonst einen einfachen Regex)
                        child.removeAttribute(attr.name);
                    }
                }
                if (tag === 'A' && child.hasAttribute('href')) {
                    child.setAttribute('rel', 'noopener noreferrer');
                }
                cleanNode(child);
            }
        }
    };
    cleanNode(doc.body);
    return doc.body.innerHTML;
}

/**
 * Zeitpunkte aus der DB ("YYYY-MM-DD HH:MM:SS") sind UTC (SQLite CURRENT_TIMESTAMP,
 * Altbeitraege aus SMF ebenfalls UTC) -> als UTC lesen und in deutscher Zeit anzeigen
 */
function parseDbDate(dateStr) {
    const s = String(dateStr).trim();
    return /^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/.test(s) ? new Date(s.replace(' ', 'T') + 'Z') : new Date(s);
}

/**
 * Format date for display
 */
function formatDate(dateStr) {
    if (!dateStr) return '';
    const date = parseDbDate(dateStr);
    return date.toLocaleDateString('de-DE', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        timeZone: 'Europe/Berlin'
    });
}

/**
 * Format date and time for display
 */
function formatDateTime(dateStr) {
    if (!dateStr) return '';
    const date = parseDbDate(dateStr);
    return date.toLocaleDateString('de-DE', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        timeZone: 'Europe/Berlin'
    });
}

// Close mobile menu on escape
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        document.getElementById('mobile-menu').classList.remove('active');
    }
});
