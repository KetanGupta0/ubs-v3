/**
 * Navigation per role.
 *
 * One definition drives the desktop sidebar, the phone tab bar and the
 * "more" sheet. `primary: true` marks the items that earn a slot in the tab
 * bar, which holds four plus More, because a fifth is unreachable by thumb.
 * Chat holds one of those four for a client and a student: it is checked more
 * often than anything else on the platform, and a tab is cheaper than a trip
 * through the More sheet twenty times a day.
 *
 * The admin menu is long, so its items carry a `group`: the sidebar shows each
 * group under a heading that can be closed (remembered per browser), and the
 * More sheet uses the same headings. Client and student menus are short
 * enough to stay one list.
 *
 * Routes referenced here land in later phases. `href: null` renders the item
 * disabled rather than linking nowhere.
 */
import {
    LayoutDashboard, FolderKanban, FileText, Receipt, LifeBuoy, MessagesSquare,
    KeyRound, RefreshCw, GraduationCap, CalendarCheck, ClipboardList, Trophy, UserCheck, Video, Star, Search, Scale, Contact,
    BookOpen, Award, Users, Briefcase, Megaphone, Settings, BarChart3, Inbox,
    UserPlus, Package, ShieldCheck, Building2, Wrench, HelpCircle, UserCog,
    ScrollText, FileSignature, Files, LifeBuoy as LifeBuoyIcon, Banknote,
    ShieldAlert,
} from 'lucide-vue-next';

export const navigation = {
    /*
     * Grouped by the work, in the order a working day touches it: what needs
     * a look today, then clients, then learning, then money, then the public
     * site, then running the platform. Items keep their group's order inside
     * it, so a new screen goes next to its neighbours, not at the bottom.
     */
    admin: [
        { group: 'Overview', label: 'Dashboard', icon: LayoutDashboard, href: '/admin', primary: true },
        { group: 'Overview', label: 'Leads', icon: Inbox, href: '/admin/leads', permission: 'leads.view', primary: true },
        { group: 'Overview', label: 'Chat', icon: MessagesSquare, href: '/chat', permission: 'chat.reply' },
        { group: 'Overview', label: 'Reports', icon: BarChart3, href: '/admin/reports' },

        { group: 'Clients', label: 'Clients', icon: Briefcase, href: '/admin/clients', permission: 'clients.view', primary: true },
        { group: 'Clients', label: 'Projects', icon: FolderKanban, href: '/admin/projects', permission: 'projects.view' },
        { group: 'Clients', label: 'Proposals', icon: FileSignature, href: '/admin/proposals', permission: 'proposals.manage' },
        { group: 'Clients', label: 'Documents', icon: Files, href: '/admin/documents', permission: 'documents.manage' },
        { group: 'Clients', label: 'Contracts', icon: ShieldCheck, href: '/admin/contracts', permission: 'support.manage' },
        { group: 'Clients', label: 'Tickets', icon: LifeBuoyIcon, href: '/admin/tickets', permission: 'support.manage' },

        { group: 'Learning', label: 'Students', icon: Users, href: '/admin/students', permission: 'students.view', primary: true },
        { group: 'Learning', label: 'Colleges', icon: Building2, href: '/admin/colleges', permission: 'students.view' },
        { group: 'Learning', label: 'Courses', icon: GraduationCap, href: '/admin/courses', permission: 'catalogue.view' },
        { group: 'Learning', label: 'Batches', icon: CalendarCheck, href: '/admin/batches', permission: 'catalogue.view' },
        { group: 'Learning', label: 'Enrolments', icon: UserCheck, href: '/admin/enrolments', permission: 'students.view' },
        { group: 'Learning', label: 'Classes', icon: Video, href: '/admin/classes?filter[when]=upcoming', permission: 'students.view' },
        { group: 'Learning', label: 'Marking', icon: ClipboardList, href: '/admin/marking', permission: 'students.view' },
        { group: 'Learning', label: 'Reviews', icon: Star, href: '/admin/reviews?filter[status]=pending', permission: 'catalogue.view' },
        { group: 'Learning', label: 'Certificate designs', icon: Award, href: '/admin/certificate-templates', permission: 'catalogue.manage' },

        { group: 'Billing', label: 'Billing', icon: Banknote, href: '/admin/billing', permission: 'billing.view' },
        { group: 'Billing', label: 'Invoices', icon: Receipt, href: '/admin/invoices', permission: 'billing.view' },
        { group: 'Billing', label: 'Subscriptions', icon: RefreshCw, href: '/admin/subscriptions', permission: 'billing.view' },
        { group: 'Billing', label: 'API plans', icon: KeyRound, href: '/admin/api-plans', permission: 'api.manage' },

        { group: 'Website', label: 'Solutions', icon: Package, href: '/admin/solutions', permission: 'catalogue.view' },
        { group: 'Website', label: 'Services', icon: Wrench, href: '/admin/services', permission: 'catalogue.view' },
        { group: 'Website', label: 'Pages', icon: FileText, href: '/admin/pages', permission: 'content.manage' },
        { group: 'Website', label: 'Site content', icon: HelpCircle, href: '/admin/content', permission: 'catalogue.view' },
        { group: 'Website', label: 'Team and mentors', icon: Contact, href: '/admin/team', permission: 'content.manage' },
        { group: 'Website', label: 'Search wording', icon: Search, href: '/admin/seo', permission: 'content.manage' },
        { group: 'Website', label: 'Legal documents', icon: Scale, href: '/admin/legal', permission: 'legal.manage' },

        { group: 'Administration', label: 'Staff', icon: UserCog, href: '/admin/staff', permission: 'staff.manage' },
        { group: 'Administration', label: 'Settings', icon: Settings, href: '/admin/settings', permission: 'settings.view' },
        { group: 'Administration', label: 'Audit log', icon: ScrollText, href: '/admin/audit-log', permission: 'audit.view' },
        { group: 'Administration', label: 'My security', icon: ShieldCheck, href: '/settings/security' },
    ],

    client: [
        { label: 'Overview', icon: LayoutDashboard, href: '/client', primary: true },
        { label: 'Projects', icon: FolderKanban, href: '/client/projects', primary: true },
        { label: 'Proposals', icon: FileSignature, href: '/client/proposals' },
        { label: 'Documents', icon: FileText, href: '/client/documents' },
        { label: 'Payments', icon: Receipt, href: '/client/payments', primary: true },
        { label: 'Transactions', icon: Banknote, href: '/client/transactions' },
        { label: 'Support', icon: LifeBuoy, href: '/client/support' },
        { label: 'Subscriptions', icon: RefreshCw, href: '/client/subscriptions' },
        { label: 'API keys', icon: KeyRound, href: '/client/api-keys' },
        { label: 'Reports', icon: BarChart3, href: '/client/reports' },
        { label: 'Chat', icon: MessagesSquare, href: '/chat', primary: true },
        { label: 'Security', icon: ShieldCheck, href: '/settings/security' },
    ],

    student: [
        { label: 'Dashboard', icon: LayoutDashboard, href: '/student', primary: true },
        { label: 'My courses', icon: BookOpen, href: '/student/courses', primary: true },
        { label: 'Live classes', icon: CalendarCheck, href: '/student/classes', primary: true },
        { label: 'Assignments', icon: FileText, href: '/student/assignments' },
        { label: 'Attendance', icon: CalendarCheck, href: '/student/attendance' },
        { label: 'Results', icon: ClipboardList, href: '/student/results' },
        { label: 'Leaderboard', icon: Trophy, href: '/student/leaderboard' },
        { label: 'Certificates', icon: Award, href: '/student/certificates' },
        { label: 'Announcements', icon: Megaphone, href: '/student/announcements' },
        { label: 'Notices', icon: ShieldAlert, href: '/student/warnings' },
        { label: 'Join a course', icon: GraduationCap, href: '/student/catalogue' },
        { label: 'Fees', icon: Receipt, href: '/student/payments' },
        { label: 'Chat', icon: MessagesSquare, href: '/chat', primary: true },
        { label: 'Security', icon: ShieldCheck, href: '/settings/security' },
    ],
};

export const roleLabels = {
    admin: 'Administrator',
    client: 'Client',
    student: 'Student',
};

/**
 * Items an account can actually reach.
 *
 * An entry carrying a permission is left out for a staff account that does not
 * hold it, because an item that always answers "you do not have access to that"
 * is worse than no item at all. The routes are gated on the server too; this is
 * only about not showing somebody a door they cannot open.
 */
export function navigationFor(role, permissions = null) {
    const items = navigation[role] ?? navigation.student;

    if (!permissions) {
        return items;
    }

    return items.filter((item) => !item.permission || permissions.includes(item.permission));
}

/**
 * Items in their groups, in order: [{ label, items }].
 *
 * A group with nothing this account can reach is left out, so a staff member
 * who only marks work does not see five empty headings. Items without a group
 * (the client and student menus) come back as one group with no label.
 */
export function groupNav(items) {
    const groups = [];

    for (const item of items) {
        const label = item.group ?? null;
        let group = groups.find((entry) => entry.label === label);

        if (!group) {
            group = { label, items: [] };
            groups.push(group);
        }

        group.items.push(item);
    }

    return groups;
}

/** Four tab bar slots. More items live behind the More sheet. */
export function primaryNavFor(role, permissions = null) {
    return navigationFor(role, permissions).filter((item) => item.primary).slice(0, 4);
}

export function secondaryNavFor(role, permissions = null) {
    const primary = new Set(primaryNavFor(role, permissions).map((item) => item.label));

    return navigationFor(role, permissions).filter((item) => !primary.has(item.label));
}

export { UserPlus };
