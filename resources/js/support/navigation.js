/**
 * Navigation per role.
 *
 * One definition drives the desktop sidebar, the phone tab bar and the
 * "more" sheet. `primary: true` marks the items that earn a slot in the tab
 * bar, which holds four plus More, because a fifth is unreachable by thumb.
 *
 * Routes referenced here land in later phases. `href: null` renders the item
 * disabled rather than linking nowhere.
 */
import {
    LayoutDashboard, FolderKanban, FileText, Receipt, LifeBuoy, MessagesSquare,
    KeyRound, RefreshCw, GraduationCap, CalendarCheck, ClipboardList, Trophy,
    BookOpen, Award, Users, Briefcase, Megaphone, Settings, BarChart3, Inbox,
    UserPlus, Package, ShieldCheck, Building2, Wrench, HelpCircle, UserCog,
    ScrollText,
} from 'lucide-vue-next';

export const navigation = {
    admin: [
        { label: 'Dashboard', icon: LayoutDashboard, href: '/admin', primary: true },
        { label: 'Leads', icon: Inbox, href: '/admin/leads', permission: 'leads.view', primary: true },
        { label: 'Clients', icon: Briefcase, href: '/admin/clients', permission: 'clients.view', primary: true },
        { label: 'Students', icon: Users, href: '/admin/students', permission: 'students.view', primary: true },
        { label: 'Colleges', icon: Building2, href: '/admin/colleges', permission: 'students.view' },
        { label: 'Projects', icon: FolderKanban, href: null },
        { label: 'Solutions', icon: Package, href: '/admin/solutions', permission: 'catalogue.view' },
        { label: 'Services', icon: Wrench, href: '/admin/services', permission: 'catalogue.view' },
        { label: 'Courses', icon: GraduationCap, href: '/admin/courses', permission: 'catalogue.view' },
        { label: 'Batches', icon: CalendarCheck, href: '/admin/batches', permission: 'catalogue.view' },
        { label: 'Site content', icon: HelpCircle, href: '/admin/content', permission: 'catalogue.view' },
        { label: 'Invoices', icon: Receipt, href: null, permission: 'billing.view' },
        { label: 'Chat', icon: MessagesSquare, href: null },
        { label: 'Reports', icon: BarChart3, href: null },
        { label: 'Staff', icon: UserCog, href: '/admin/staff', permission: 'staff.manage' },
        { label: 'Settings', icon: Settings, href: '/admin/settings', permission: 'settings.view' },
        { label: 'Audit log', icon: ScrollText, href: '/admin/audit-log', permission: 'audit.view' },
        { label: 'Security', icon: ShieldCheck, href: '/settings/security' },
    ],

    client: [
        { label: 'Overview', icon: LayoutDashboard, href: '/client', primary: true },
        { label: 'Projects', icon: FolderKanban, href: null, primary: true },
        { label: 'Documents', icon: FileText, href: null },
        { label: 'Payments', icon: Receipt, href: null, primary: true },
        { label: 'Support', icon: LifeBuoy, href: null },
        { label: 'Chat', icon: MessagesSquare, href: null, primary: true },
        { label: 'API keys', icon: KeyRound, href: null },
        { label: 'Subscriptions', icon: RefreshCw, href: null },
        { label: 'Security', icon: ShieldCheck, href: '/settings/security' },
    ],

    student: [
        { label: 'Dashboard', icon: LayoutDashboard, href: '/student', primary: true },
        { label: 'My courses', icon: BookOpen, href: null, primary: true },
        { label: 'Live classes', icon: CalendarCheck, href: null, primary: true },
        { label: 'Quizzes', icon: ClipboardList, href: null },
        { label: 'Assignments', icon: FileText, href: null },
        { label: 'Attendance', icon: CalendarCheck, href: null },
        { label: 'Leaderboard', icon: Trophy, href: null },
        { label: 'Certificates', icon: Award, href: null },
        { label: 'Chat', icon: MessagesSquare, href: null, primary: true },
        { label: 'Announcements', icon: Megaphone, href: null },
        { label: 'Payments', icon: Receipt, href: null },
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

/** Four tab bar slots. More items live behind the More sheet. */
export function primaryNavFor(role, permissions = null) {
    return navigationFor(role, permissions).filter((item) => item.primary).slice(0, 4);
}

export function secondaryNavFor(role, permissions = null) {
    const primary = new Set(primaryNavFor(role, permissions).map((item) => item.label));

    return navigationFor(role, permissions).filter((item) => !primary.has(item.label));
}

export { UserPlus };
