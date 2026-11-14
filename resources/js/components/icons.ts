// Material Symbols (outlined), the icon set the legacy pages used.
// Each icon is imported on its own, so only these end up in the bundle.
// Add a name here to use a new icon.
import AccountCircle from '@material-symbols/svg-400/outlined/account_circle.svg?raw';
import Add from '@material-symbols/svg-400/outlined/add.svg?raw';
import ArrowForward from '@material-symbols/svg-400/outlined/arrow_forward.svg?raw';
import Badge from '@material-symbols/svg-400/outlined/badge.svg?raw';
import CalendarMonth from '@material-symbols/svg-400/outlined/calendar_month.svg?raw';
import Cancel from '@material-symbols/svg-400/outlined/cancel.svg?raw';
import Check from '@material-symbols/svg-400/outlined/check.svg?raw';
import CheckCircle from '@material-symbols/svg-400/outlined/check_circle.svg?raw';
import ChevronLeft from '@material-symbols/svg-400/outlined/chevron_left.svg?raw';
import ChevronRight from '@material-symbols/svg-400/outlined/chevron_right.svg?raw';
import Close from '@material-symbols/svg-400/outlined/close.svg?raw';
import CloudUpload from '@material-symbols/svg-400/outlined/cloud_upload.svg?raw';
import ContentCopy from '@material-symbols/svg-400/outlined/content_copy.svg?raw';
import Dashboard from '@material-symbols/svg-400/outlined/dashboard.svg?raw';
import Delete from '@material-symbols/svg-400/outlined/delete.svg?raw';
import Description from '@material-symbols/svg-400/outlined/description.svg?raw';
import Download from '@material-symbols/svg-400/outlined/download.svg?raw';
import Edit from '@material-symbols/svg-400/outlined/edit.svg?raw';
import Error from '@material-symbols/svg-400/outlined/error.svg?raw';
import FactCheck from '@material-symbols/svg-400/outlined/fact_check.svg?raw';
import FilterAlt from '@material-symbols/svg-400/outlined/filter_alt.svg?raw';
import FormatSize from '@material-symbols/svg-400/outlined/format_size.svg?raw';
import Groups from '@material-symbols/svg-400/outlined/groups.svg?raw';
import History from '@material-symbols/svg-400/outlined/history.svg?raw';
import HowToReg from '@material-symbols/svg-400/outlined/how_to_reg.svg?raw';
import Info from '@material-symbols/svg-400/outlined/info.svg?raw';
import Key from '@material-symbols/svg-400/outlined/key.svg?raw';
import KeyboardArrowDown from '@material-symbols/svg-400/outlined/keyboard_arrow_down.svg?raw';
import Lock from '@material-symbols/svg-400/outlined/lock.svg?raw';
import LockReset from '@material-symbols/svg-400/outlined/lock_reset.svg?raw';
import Logout from '@material-symbols/svg-400/outlined/logout.svg?raw';
import Menu from '@material-symbols/svg-400/outlined/menu.svg?raw';
import MoreVert from '@material-symbols/svg-400/outlined/more_vert.svg?raw';
import Pending from '@material-symbols/svg-400/outlined/pending.svg?raw';
import Person from '@material-symbols/svg-400/outlined/person.svg?raw';
import PersonAdd from '@material-symbols/svg-400/outlined/person_add.svg?raw';
import PersonCheck from '@material-symbols/svg-400/outlined/person_check.svg?raw';
import PersonOff from '@material-symbols/svg-400/outlined/person_off.svg?raw';
import Refresh from '@material-symbols/svg-400/outlined/refresh.svg?raw';
import Save from '@material-symbols/svg-400/outlined/save.svg?raw';
import School from '@material-symbols/svg-400/outlined/school.svg?raw';
import Search from '@material-symbols/svg-400/outlined/search.svg?raw';
import Settings from '@material-symbols/svg-400/outlined/settings.svg?raw';
import Restore from '@material-symbols/svg-400/outlined/settings_backup_restore.svg?raw';
import TableView from '@material-symbols/svg-400/outlined/table_view.svg?raw';
import TaskAlt from '@material-symbols/svg-400/outlined/task_alt.svg?raw';
import TextIncrease from '@material-symbols/svg-400/outlined/text_increase.svg?raw';
import UploadFile from '@material-symbols/svg-400/outlined/upload_file.svg?raw';
import Visibility from '@material-symbols/svg-400/outlined/visibility.svg?raw';
import VisibilityOff from '@material-symbols/svg-400/outlined/visibility_off.svg?raw';
import Warning from '@material-symbols/svg-400/outlined/warning.svg?raw';

export const icons = {
    account_circle: AccountCircle,
    add: Add,
    arrow_forward: ArrowForward,
    badge: Badge,
    calendar_month: CalendarMonth,
    cancel: Cancel,
    check: Check,
    check_circle: CheckCircle,
    chevron_left: ChevronLeft,
    chevron_right: ChevronRight,
    close: Close,
    cloud_upload: CloudUpload,
    content_copy: ContentCopy,
    dashboard: Dashboard,
    delete: Delete,
    description: Description,
    download: Download,
    edit: Edit,
    error: Error,
    keyboard_arrow_down: KeyboardArrowDown,
    fact_check: FactCheck,
    filter_alt: FilterAlt,
    format_size: FormatSize,
    groups: Groups,
    history: History,
    how_to_reg: HowToReg,
    info: Info,
    key: Key,
    lock: Lock,
    lock_reset: LockReset,
    logout: Logout,
    menu: Menu,
    more_vert: MoreVert,
    pending: Pending,
    person: Person,
    person_add: PersonAdd,
    person_check: PersonCheck,
    person_off: PersonOff,
    refresh: Refresh,
    restore: Restore,
    save: Save,
    school: School,
    search: Search,
    settings: Settings,
    table_view: TableView,
    task_alt: TaskAlt,
    text_increase: TextIncrease,
    upload_file: UploadFile,
    visibility: Visibility,
    visibility_off: VisibilityOff,
    warning: Warning,
} as const;

export type IconName = keyof typeof icons;
