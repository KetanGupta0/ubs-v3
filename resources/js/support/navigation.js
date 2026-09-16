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
    UserPlus, Package, ShieldCheck,
} from 'lucide-vue-next';

export const navigation = {
    admin: [
        { label: 'Dashboard', icon: LayoutDashboard, href: '/admin', primary: true },
        { label: 'Leads', icon: Inbox, href: null, primary: true },
        { label: 'Clients', icon: Briefcase, href: null, primary: true },
        { label: 'Projects', icon: FolderKanban, href: null },
        { label: 'Students', icon: Users, href: null, primary: true },
        { label: 'Courses', icon: GraduationCap, href: null },
        { label: 'Batches', icon: CalendarCheck, href: null },
        { label: 'Catalogue', icon: Package, href: null },
        { label: 'Invoices', icon: Receipt, href: null },
        { label: 'Chat', icon: MessagesSquare, href: null },
        { label: 'Reports', icon: BarChart3, href: null },
        { label: 'Settings', icon: Settings, href: null },
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

export function navigationFor(role) {
    return navigation[role] ?? navigation.student;
}

/** Four tab bar slots. More items live behind the More sheet. */
export function primaryNavFor(role) {
    return navigationFor(role).filter((item) => item.primary).slice(0, 4);
}

export function secondaryNavFor(role) {
    const primary = new Set(primaryNavFor(role).map((item) => item.label));

    return navigationFor(role).filter((item) => !primary.has(item.label));
}

export { UserPlus };
