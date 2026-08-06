const { createApp, ref, computed, reactive, watch, onMounted, nextTick, onBeforeUnmount } = Vue;

const API_BASE = '/api';
const TOKEN_KEY = 'bms_token';
const USER_KEY = 'bms_user';
const LEGACY_TOKEN_KEY = 'bftbg_token';
const LEGACY_USER_KEY = 'bftbg_user';

function formatRp(value) {
  const n = Number(value || 0);
  return new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 0,
  }).format(n);
}

function formatInputNumber(raw) {
  const digits = String(raw || '').replace(/\D/g, '');
  if (!digits) return '';
  return new Intl.NumberFormat('id-ID').format(Number(digits));
}

function parseInputNumber(formatted) {
  return Number(String(formatted || '').replace(/\D/g, '') || 0);
}

function today() {
  return new Date().toISOString().slice(0, 10);
}

function formatDateTime(value) {
  if (!value) return '—';
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return String(value).slice(0, 16).replace('T', ' ');
  return new Intl.DateTimeFormat('id-ID', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  }).format(d);
}

function formatDate(value) {
  if (!value) return '—';
  const s = String(value).slice(0, 10);
  const d = new Date(`${s}T00:00:00`);
  if (Number.isNaN(d.getTime())) return s;
  return new Intl.DateTimeFormat('id-ID', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
  }).format(d);
}

function currentPeriod() {
  return new Date().toISOString().slice(0, 7);
}

function monthStart() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-01`;
}

createApp({
  setup() {
    const token = ref(localStorage.getItem(TOKEN_KEY) || localStorage.getItem(LEGACY_TOKEN_KEY) || '');
    const user = ref(JSON.parse(localStorage.getItem(USER_KEY) || localStorage.getItem(LEGACY_USER_KEY) || 'null'));
    if (token.value && !localStorage.getItem(TOKEN_KEY) && localStorage.getItem(LEGACY_TOKEN_KEY)) {
      localStorage.setItem(TOKEN_KEY, token.value);
      localStorage.removeItem(LEGACY_TOKEN_KEY);
    }
    if (user.value && !localStorage.getItem(USER_KEY) && localStorage.getItem(LEGACY_USER_KEY)) {
      localStorage.setItem(USER_KEY, JSON.stringify(user.value));
      localStorage.removeItem(LEGACY_USER_KEY);
    }
    const page = ref('dashboard');
    const loading = ref(false);
    const dashLoading = ref(false);
    const bootLoading = ref(!!token.value);
    const toasts = ref([]);
    const showPassword = ref(false);
    const navGroups = reactive({
      utama: true,
      transfer: true,
      laporan: true,
      karyawan: true,
      konter: true,
      bengkel: true,
      sistem: false,
    });

    const loginForm = reactive({ email: '', password: 'password', remember: false });
    const demoAccounts = ref([]);
    const demoPasswordHint = ref('password');
    const loginError = ref('');

    const profileForm = reactive({
      name: '',
      email: '',
      current_password: '',
      password: '',
      password_confirmation: '',
    });

    const categories = ref([]);
    const accountForm = reactive({
      id: null,
      name: '',
      code: '',
      is_active: true,
      sort_order: 0,
    });

    const accounts = ref([]);
    const allAccounts = ref([]);
    const accountAssignTypeId = ref('');
    const accountAssignTypeIds = ref([]);
    const accountAssignBranchId = ref('');
    const accountAssignBranchMode = ref('type');
    const accountAssignBranchIds = ref([]);
    const accountAssignTypePreviewIds = ref([]);
    const openingForm = reactive({
      branch_id: '',
      account_id: '',
      amount: '',
      effective_date: today(),
    });
    const openingBalances = ref([]);
    const branches = ref([]);
    const branchTypes = ref([]);
    const ownerData = ref(null);
    const ownerDashBranchId = ref('');
    const ownerCategoryBranchId = ownerDashBranchId; // alias kompatibilitas
    const ownerDashMonth = ref(new Date().getMonth() + 1);
    const ownerDashYear = ref(new Date().getFullYear());
    const ownerDashYears = computed(() => {
      const y = new Date().getFullYear();
      const list = [];
      for (let i = y; i >= y - 5; i -= 1) list.push(i);
      return list;
    });
    const ownerDashMonths = [
      { value: 1, label: 'Januari' },
      { value: 2, label: 'Februari' },
      { value: 3, label: 'Maret' },
      { value: 4, label: 'April' },
      { value: 5, label: 'Mei' },
      { value: 6, label: 'Juni' },
      { value: 7, label: 'Juli' },
      { value: 8, label: 'Agustus' },
      { value: 9, label: 'September' },
      { value: 10, label: 'Oktober' },
      { value: 11, label: 'November' },
      { value: 12, label: 'Desember' },
    ];
    const branchData = ref(null);
    const transfers = ref([]);
    const transactions = ref([]);
    const periodLocks = ref([]);
    const reportResult = ref(null);

    const allReportTypes = [
      { id: 'ringkasan', title: 'Ringkasan Periode', desc: 'Pemasukan, pengeluaran, dan selisih harian', group: 'keuangan' },
      { id: 'kategori', title: 'Per Kategori', desc: 'Rekap total per kategori pemasukan & pengeluaran', group: 'keuangan' },
      { id: 'akun', title: 'Saldo per Akun', desc: 'Posisi saldo Cash, Mandiri, BRI, GoPay', group: 'keuangan' },
      { id: 'transaksi', title: 'Detail Transaksi', desc: 'Daftar lengkap transaksi sesuai filter', group: 'keuangan' },
      { id: 'transfer', title: 'Transfer Antar Cabang', desc: 'Riwayat pengajuan dan status transfer', group: 'keuangan' },
      { id: 'rekonsiliasi', title: 'Rekonsiliasi', desc: 'Riwayat cek fisik vs sistem dan selisih', group: 'keuangan' },
      { id: 'servis', title: 'Catatan Servis', desc: 'Job servis, modal, harga, dan profit', group: 'konter' },
      { id: 'closing', title: 'Target Closingan', desc: 'Closing vs target bulanan per karyawan', group: 'konter' },
      { id: 'gaji', title: 'Gaji Konter', desc: 'Rekap gaji karyawan konter per bulan', group: 'konter' },
      { id: 'upah', title: 'Upah Bengkel', desc: 'Job dan upah teknisi bengkel', group: 'bengkel' },
      { id: 'absensi', title: 'Absensi', desc: 'Rekap H/I/S/A per karyawan', group: 'karyawan' },
    ];

    const reportTypes = computed(() => allReportTypes.filter((rt) => {
      if (rt.id === 'gaji') return isOwner.value;
      if (rt.id === 'servis' || rt.id === 'closing') return isOwner.value || !isWorkshopBranch.value;
      if (rt.id === 'upah') return isOwner.value || isWorkshopBranch.value;
      return true;
    }));

    const reportForm = reactive({
      type: 'ringkasan',
      branch_id: '',
      date_from: monthStart(),
      date_to: today(),
      type_filter: '',
      category_id: '',
      account_id: '',
      q: '',
    });

    const txForm = reactive({
      type: 'income',
      category_id: '',
      account_id: '',
      amount: '',
      transaction_date: today(),
      description: '',
      branch_id: '',
    });

    const txFilter = reactive({
      type: '',
      category_id: '',
      date_from: '',
      date_to: '',
      q: '',
    });
    const txMeta = ref({ total: 0, current_page: 1, last_page: 1, per_page: 20 });
    let txSearchTimer = null;

    const transferForm = reactive({
      to_branch_id: '',
      amount: '',
      from_branch_id: '',
      account_id: '',
      reason: '',
    });

    const internalTransferForm = reactive({
      branch_id: '',
      from_account_id: '',
      to_account_id: '',
      amount: '',
      transaction_date: today(),
      description: '',
    });

    const adjustmentForm = reactive({
      branch_id: '',
      account_id: '',
      type: 'income',
      amount: '',
      reason: '',
      transaction_date: today(),
    });

    const branchForm = reactive({
      id: null,
      name: '',
      type: 'konter',
      address: '',
      status: 'active',
    });

    const branchTypeForm = reactive({
      id: null,
      code: '',
      name: '',
      allows_service: true,
      status: 'active',
    });

    const adminForm = reactive({
      id: null,
      branch_id: '',
      name: '',
      email: '',
      password: '',
    });

    const categoryForm = reactive({
      id: null,
      name: '',
      type: 'income',
      branch_id: '',
      is_active: true,
    });

    const admins = ref([]);
    const employees = ref([]);
    const kelolaTab = ref('branches');

    const employeePositionOptions = [
      { value: 'owner', label: 'Owner' },
      { value: 'pic', label: 'PIC' },
      { value: 'kasir', label: 'Kasir' },
      { value: 'promotor', label: 'Promotor' },
      { value: 'fronliner', label: 'Fronliner' },
      { value: 'teknisi', label: 'Teknisi' },
    ];

    const employeeForm = reactive({
      id: null,
      branch_id: '',
      name: '',
      phone: '',
      positions: [],
      status: 'active',
      joined_at: '',
      notes: '',
    });

    const employeeFilter = reactive({
      branch_id: '',
      status: '',
      q: '',
    });

    const closingBoard = ref({ meta: null, data: [], groups: [] });
    const _closingDefault = (() => {
      const d = new Date();
      d.setMonth(d.getMonth() - 1);
      return { month: d.getMonth() + 1, year: d.getFullYear() };
    })();
    const closingFilter = reactive({
      branch_id: '',
      month: _closingDefault.month,
      year: _closingDefault.year,
    });
    const closingYears = computed(() => {
      const y = new Date().getFullYear();
      return [y - 1, y, y + 1];
    });
    const closingDays = computed(() => {
      const n = Number(closingBoard.value?.meta?.days_in_month || 31);
      return Array.from({ length: n }, (_, i) => i + 1);
    });
    const canAccessClosings = computed(() => isOwner.value || (isAdmin.value && !isWorkshopBranch.value));
    const canAccessAttendance = computed(() => isOwner.value || isAdmin.value);
    const konterBranches = computed(() =>
      branches.value.filter((b) => {
        if (typeof b.allows_service === 'boolean') return b.allows_service;
        return String(b.type || '').toLowerCase() === 'konter';
      })
    );

    const attendanceTab = ref('daily');
    const attendanceDailyDate = ref(today());
    const attendanceDailyRows = ref([]);
    const attendanceDailyMeta = ref(null);
    const attendanceBoard = ref({ meta: null, data: [], groups: [] });
    const _attDefault = (() => {
      const d = new Date();
      return { month: d.getMonth() + 1, year: d.getFullYear() };
    })();
    const attendanceFilter = reactive({
      branch_id: '',
      month: _attDefault.month,
      year: _attDefault.year,
    });
    const attendanceYears = computed(() => {
      const y = new Date().getFullYear();
      return [y - 1, y, y + 1];
    });
    const attendanceDays = computed(() => {
      const n = Number(attendanceBoard.value?.meta?.days_in_month || 31);
      return Array.from({ length: n }, (_, i) => i + 1);
    });
    const attendanceDailyCounts = computed(() => {
      const counts = { present: 0, leave: 0, sick: 0, absent: 0, empty: 0 };
      for (const row of attendanceDailyRows.value) {
        if (row.status && counts[row.status] != null) counts[row.status]++;
        else counts.empty++;
      }
      return counts;
    });
    const attendanceStatusOptions = [
      { value: 'present', label: 'Hadir', short: 'H' },
      { value: 'leave', label: 'Izin', short: 'I' },
      { value: 'sick', label: 'Sakit', short: 'S' },
      { value: 'absent', label: 'Alpha', short: 'A' },
    ];

    const canAccessPayroll = computed(() => isOwner.value);
    const canAccessWorkshopWages = computed(() => isOwner.value || (isAdmin.value && isWorkshopBranch.value));
    const workshopBranches = computed(() =>
      branches.value.filter((b) => {
        if (typeof b.allows_service === 'boolean') return !b.allows_service;
        return String(b.type || '').toLowerCase() === 'bengkel';
      })
    );

    const wwTab = ref('weekly');
    const wwDailyDate = ref(today());
    const wwJobs = ref([]);
    const wwTechnicians = ref([]);
    const wwJobTypes = ref([]);
    const wwWeeks = ref([]);
    const wwWeekDetail = ref(null);
    const wwMeta = ref(null);
    const _wwDefault = (() => {
      const d = new Date();
      return { month: d.getMonth() + 1, year: d.getFullYear() };
    })();
    function previousMondayDate() {
      const d = new Date();
      const day = d.getDay();
      const toMon = day === 0 ? 6 : day - 1;
      d.setDate(d.getDate() - toMon - 7);
      const y = d.getFullYear();
      const m = String(d.getMonth() + 1).padStart(2, '0');
      const dd = String(d.getDate()).padStart(2, '0');
      return `${y}-${m}-${dd}`;
    }
    const wwFilter = reactive({
      branch_id: '',
      month: _wwDefault.month,
      year: _wwDefault.year,
      week_start: previousMondayDate(),
    });
    const wwYears = computed(() => {
      const y = new Date().getFullYear();
      return [y - 1, y, y + 1];
    });
    const wwSettingsRows = ref([]);
    const wwSettingsMeta = ref(null);
    const wwJobForm = reactive({
      id: null,
      employee_id: '',
      job_date: today(),
      job_type: 'ONGKER',
      amount: '',
      note: '',
    });

    const payrollBoard = ref({ meta: null, data: [], groups: [] });
    const _payDefault = (() => {
      const d = new Date();
      return { month: d.getMonth() + 1, year: d.getFullYear() };
    })();
    const payrollFilter = reactive({
      branch_id: '',
      month: _payDefault.month,
      year: _payDefault.year,
    });
    const payrollYears = computed(() => {
      const y = new Date().getFullYear();
      return [y - 1, y, y + 1];
    });
    const payrollDetail = reactive({
      open: false,
      loading: false,
      data: null,
      services: [],
      meta: null,
    });
    const payrollMonthLabel = computed(() => {
      const m = Number(payrollFilter.month);
      const y = Number(payrollFilter.year);
      try {
        return new Intl.DateTimeFormat('id-ID', { month: 'long', year: 'numeric' })
          .format(new Date(y, m - 1, 1));
      } catch (_) {
        return `${m}/${y}`;
      }
    });

    const serviceRecords = ref([]);
    const serviceSummary = ref({ jumlah: 0, total_modal: 0, total_harga: 0, total_profit: 0 });
    const serviceTechnicians = ref([]);

    const serviceForm = reactive({
      id: null,
      employee_id: '',
      service_date: today(),
      brand: '',
      device_type: '',
      damage: '',
      cost: '',
      price: '',
      notes: '',
    });

    const serviceFilter = reactive({
      branch_id: '',
      employee_id: '',
      date_from: '',
      date_to: '',
      q: '',
    });

    const employeesByBranch = computed(() => {
      const map = new Map();
      for (const emp of employees.value) {
        const key = emp.branch_id || emp.branch?.id || 0;
        const label = emp.branch?.name || 'Tanpa cabang';
        if (!map.has(key)) {
          map.set(key, { branch_id: key, nama_cabang: label, items: [] });
        }
        map.get(key).items.push(emp);
      }
      return Array.from(map.values());
    });

    const reconForm = reactive({
      account_id: '',
      physical_balance: '',
      reconciliation_date: today(),
      branch_id: '',
    });

    const lockForm = reactive({
      branch_id: '',
      period: currentPeriod(),
      is_locked: true,
    });

    const rejectModal = reactive({
      open: false,
      transferId: null,
      reason: '',
    });

    const approveModal = reactive({
      open: false,
      transferId: null,
      password: '',
    });

    const closingConfirm = reactive({
      open: false,
      title: '',
      message: '',
      detail: '',
      confirmLabel: 'Simpan',
      danger: false,
    });
    let closingConfirmResolve = null;

    function askClosingConfirm({ title, message, detail = '', confirmLabel = 'Simpan', danger = false }) {
      return new Promise((resolve) => {
        closingConfirmResolve = resolve;
        closingConfirm.title = title;
        closingConfirm.message = message;
        closingConfirm.detail = detail;
        closingConfirm.confirmLabel = confirmLabel;
        closingConfirm.danger = danger;
        closingConfirm.open = true;
      });
    }

    function resolveClosingConfirm(ok) {
      closingConfirm.open = false;
      if (closingConfirmResolve) {
        closingConfirmResolve(ok);
        closingConfirmResolve = null;
      }
    }

    const editTxModal = reactive({
      open: false,
      id: null,
      type: 'income',
      category_id: '',
      account_id: '',
      amount: '',
      transaction_date: '',
      description: '',
    });

    let barChart, lineChart, donutChart;

    const isOwner = computed(() => user.value?.role === 'owner');
    const isAdmin = computed(() => user.value?.role === 'admin');
    const roleLabel = computed(() => {
      if (isOwner.value) return 'Pemilik';
      const branch = user.value?.branch?.name;
      return branch ? `Admin · ${branch}` : 'Admin';
    });
    function initialsOf(name) {
      const parts = String(name || '')
        .trim()
        .split(/\s+/)
        .filter(Boolean);
      if (!parts.length) return '?';
      if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
      return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
    }

    function rowNo(index, page = 1, perPage = 0) {
      const i = Number(index) || 0;
      const p = Number(page) || 1;
      const pp = Number(perPage) || 0;
      return pp > 0 ? (p - 1) * pp + i + 1 : i + 1;
    }

    const userInitials = computed(() => initialsOf(user.value?.name));

    const filteredCategories = computed(() =>
      categories.value.filter((c) => c.type === txForm.type && c.is_active !== false)
    );
    const expenseCategories = computed(() =>
      categories.value.filter((c) => c.type === 'expense' && c.is_active !== false)
    );
    const incomeCategories = computed(() =>
      categories.value.filter((c) => c.type === 'income' && c.is_active !== false)
    );
    const editTxCategories = computed(() =>
      categories.value.filter((c) => {
        if (c.type !== editTxModal.type) return false;
        if (c.is_active !== false) return true;
        return Number(c.id) === Number(editTxModal.category_id);
      })
    );
    const destinationBranches = computed(() =>
      branches.value.filter((b) => !user.value?.branch_id || b.id !== user.value.branch_id)
    );

    const ownerMetrics = computed(() => {
      const periode = ownerData.value?.periode;
      const balances = ownerData.value?.saldo_per_cabang || [];
      if (periode) {
        const saldoFromBalances = balances.reduce((s, r) => s + Number(r.saldo || 0), 0);
        return {
          omzet: Number(periode.omzet || 0),
          beban: Number(periode.beban || 0),
          profit: Number(periode.profit || 0),
          saldo: balances.length
            ? saldoFromBalances
            : (ownerData.value?.saldo_kas != null
              ? Number(ownerData.value.saldo_kas)
              : Number(periode.profit || 0)),
          change: ownerData.value?.periode_change || {},
        };
      }
      const rows = ownerData.value?.agregat_cabang || [];
      const omzet = rows.reduce((s, r) => s + Number(r.pemasukan || 0), 0);
      const beban = rows.reduce((s, r) => s + Number(r.pengeluaran || 0), 0);
      const saldoFromBalances = balances.reduce((s, r) => s + Number(r.saldo || 0), 0);
      return {
        omzet,
        beban,
        profit: omzet - beban,
        saldo: balances.length
          ? saldoFromBalances
          : rows.reduce((s, r) => s + Number(r.saldo || 0), 0),
        change: {},
      };
    });

    const ownerServiceMetrics = computed(() => ownerData.value?.service || {
      jumlah: 0, total_harga: 0, total_profit: 0, per_cabang: [],
    });

    const ownerDashShowsService = computed(() => {
      const scope = ownerData.value?.scope;
      if (!scope || scope.is_konter === null || scope.is_konter === undefined) return true;
      return !!scope.allows_service;
    });

    const ownerDashShowsClosing = computed(() => {
      const scope = ownerData.value?.scope;
      if (!scope || scope.is_konter === null || scope.is_konter === undefined) return true;
      return !!scope.is_konter;
    });

    const ownerDashShowsWorkshop = computed(() => {
      const scope = ownerData.value?.scope;
      if (!scope || scope.is_workshop === null || scope.is_workshop === undefined) {
        return !!(ownerData.value?.workshop_week);
      }
      return !!scope.is_workshop || !ownerDashBranchId.value;
    });

    function formatPct(value) {
      if (value === null || value === undefined || Number.isNaN(Number(value))) return 'n/a';
      const n = Number(value);
      const sign = n > 0 ? '+' : '';
      return `${sign}${n.toFixed(1)}%`;
    }

    function pctClass(value) {
      if (value === null || value === undefined) return '';
      const n = Number(value);
      if (Math.abs(n) < 0.05) return '';
      return n > 0 ? 'value-income' : 'value-expense';
    }

    const reconSystemBalance = computed(() => {
      const accountId = Number(reconForm.account_id);
      const rows = branchData.value?.saldo_per_akun || [];
      if (accountId) {
        const row = rows.find((a) => Number(a.account_id) === accountId);
        return Number(row?.saldo || 0);
      }
      return Number(branchData.value?.saldo_kas || 0);
    });

    const reconDifference = computed(() => {
      const fisik = parseInputNumber(reconForm.physical_balance);
      return fisik - reconSystemBalance.value;
    });

    const periodLocked = computed(() => !!branchData.value?.kunci_periode?.is_locked);

    const isWorkshopBranch = computed(() => {
      const b = user.value?.branch;
      if (b && typeof b.allows_service === 'boolean') {
        return !b.allows_service;
      }
      const type = String(b?.type || '').trim().toLowerCase();
      const meta = branchTypes.value.find((t) => t.code === type);
      if (meta) return !meta.allows_service;
      const name = String(b?.name || '').trim().toLowerCase();
      return type === 'bengkel' || name === 'bengkel';
    });

    const activeBranchTypes = computed(() =>
      branchTypes.value.filter((t) => t.status === 'active')
    );

    function branchTypeLabel(type) {
      const found = branchTypes.value.find((t) => t.code === type);
      return found?.name || type || '—';
    }

    const canInputService = computed(() => isAdmin.value && !isWorkshopBranch.value);

    const serviceProfitPreview = computed(() => {
      const price = parseInputNumber(serviceForm.price);
      const cost = parseInputNumber(serviceForm.cost);
      return price - cost;
    });

    const utamaPages = ['dashboard', 'transactions', 'recon', 'branch-accounts', 'branch-categories'];
    const transferPages = ['internal-transfer', 'transfers'];
    const laporanPages = ['reports'];
    const karyawanPages = ['employees', 'attendance'];
    const konterPages = ['closings', 'payroll', 'services'];
    const bengkelPages = ['workshop-wages'];
    const sistemPages = ['kelola', 'adjustments', 'locks'];

    const canAccessKonterMenu = computed(() => canAccessClosings.value || canAccessPayroll.value);

    function toggleNavGroup(key) {
      navGroups[key] = !navGroups[key];
    }

    function syncNavGroups(nextPage = page.value) {
      if (utamaPages.includes(nextPage)) navGroups.utama = true;
      if (transferPages.includes(nextPage)) navGroups.transfer = true;
      if (laporanPages.includes(nextPage)) navGroups.laporan = true;
      if (karyawanPages.includes(nextPage)) navGroups.karyawan = true;
      if (konterPages.includes(nextPage)) navGroups.konter = true;
      if (bengkelPages.includes(nextPage)) navGroups.bengkel = true;
      if (sistemPages.includes(nextPage)) navGroups.sistem = true;
    }

    function toast(message, type = 'info') {
      const id = Date.now() + Math.random();
      toasts.value.push({ id, message, type });
      setTimeout(() => {
        toasts.value = toasts.value.filter((t) => t.id !== id);
      }, 4200);
    }

    async function api(path, options = {}) {
      const headers = {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        ...(options.headers || {}),
      };
      if (token.value) headers.Authorization = `Bearer ${token.value}`;

      const res = await fetch(`${API_BASE}${path}`, { ...options, headers });
      let data = null;
      try {
        data = await res.json();
      } catch (_) {
        data = null;
      }

      if (res.status === 401) {
        logout(false);
        throw new Error(data?.message || 'Sesi berakhir. Silakan masuk kembali.');
      }

      if (res.status === 403) {
        const msg =
          data?.message ||
          'Aksi Ditolak: Periode pembukuan telah dikunci oleh Owner.';
        toast(msg, 'error');
        const err = new Error(msg);
        err.status = 403;
        throw err;
      }

      if (!res.ok) {
        const msg =
          data?.message ||
          (data?.errors && Object.values(data.errors).flat()[0]) ||
          'Terjadi kesalahan.';
        toast(msg, 'error');
        const err = new Error(msg);
        err.status = res.status;
        throw err;
      }

      return data;
    }

    function persistAuth(nextToken, nextUser) {
      token.value = nextToken;
      user.value = nextUser;
      localStorage.setItem(TOKEN_KEY, nextToken);
      localStorage.setItem(USER_KEY, JSON.stringify(nextUser));
    }

    function logout(showToast = true) {
      token.value = '';
      user.value = null;
      localStorage.removeItem(TOKEN_KEY);
      localStorage.removeItem(USER_KEY);
      localStorage.removeItem(LEGACY_TOKEN_KEY);
      localStorage.removeItem(LEGACY_USER_KEY);
      ownerData.value = null;
      branchData.value = null;
      if (showToast) toast('Anda telah keluar.', 'info');
    }

    async function doLogin() {
      loginError.value = '';
      loading.value = true;
      try {
        const data = await api('/auth/login', {
          method: 'POST',
          body: JSON.stringify({
            email: loginForm.email,
            password: loginForm.password,
          }),
        });
        persistAuth(data.token, data.user);
        toast('Berhasil masuk.', 'success');
        await bootstrapApp();
      } catch (e) {
        loginError.value = e.message;
      } finally {
        loading.value = false;
      }
    }

    async function doLogout() {
      try {
        await api('/auth/logout', { method: 'POST' });
      } catch (_) {}
      logout();
      await loadDemoAccounts();
    }

    function fillProfileForm() {
      profileForm.name = user.value?.name || '';
      profileForm.email = user.value?.email || '';
      profileForm.current_password = '';
      profileForm.password = '';
      profileForm.password_confirmation = '';
    }

    async function submitProfile() {
      if (!profileForm.name.trim() || !profileForm.email.trim()) {
        toast('Nama dan email wajib diisi.', 'error');
        return;
      }
      if (!profileForm.current_password) {
        toast('Kata sandi saat ini wajib diisi untuk menyimpan perubahan.', 'error');
        return;
      }
      if (profileForm.password && profileForm.password !== profileForm.password_confirmation) {
        toast('Konfirmasi kata sandi baru tidak cocok.', 'error');
        return;
      }
      if (profileForm.password && profileForm.password.length < 6) {
        toast('Kata sandi baru minimal 6 karakter.', 'error');
        return;
      }
      loading.value = true;
      try {
        const payload = {
          name: profileForm.name.trim(),
          email: profileForm.email.trim(),
          current_password: profileForm.current_password,
        };
        if (profileForm.password) {
          payload.password = profileForm.password;
          payload.password_confirmation = profileForm.password_confirmation;
        }
        const data = await api('/auth/profile', {
          method: 'PUT',
          body: JSON.stringify(payload),
        });
        user.value = data.data;
        localStorage.setItem(USER_KEY, JSON.stringify(data.data));
        fillProfileForm();
        toast(data.message || 'Profil berhasil diperbarui.', 'success');
      } catch (_) {
      } finally {
        loading.value = false;
      }
    }

    async function loadDemoAccounts() {
      demoAccounts.value = [];
      try {
        const data = await api('/auth/demo-accounts');
        demoAccounts.value = data.data || [];
        demoPasswordHint.value = data.password_hint || 'password';
        if (!loginForm.email && demoAccounts.value.length) {
          const owner = demoAccounts.value.find((a) => a.role === 'owner') || demoAccounts.value[0];
          loginForm.email = owner.email;
          loginForm.password = demoPasswordHint.value;
        }
      } catch (_) {
        demoAccounts.value = [];
      }
    }

    function useDemoAccount(account) {
      loginForm.email = account.email;
      loginForm.password = demoPasswordHint.value;
      loginError.value = '';
    }

    async function loadCategories() {
      const data = await api('/categories?include_inactive=1');
      categories.value = data.data || [];
    }

    const SYSTEM_CATEGORY_NAMES = [
      'Transfer Antar Akun - Keluar',
      'Transfer Antar Akun - Masuk',
      'Transfer Keluar Cabang',
      'Transfer Masuk Cabang',
      'Penyesuaian Saldo - Pemasukan',
      'Penyesuaian Saldo - Pengeluaran',
    ];

    function isSystemCategory(c) {
      const name = (c?.name || '').toString();
      return SYSTEM_CATEGORY_NAMES.includes(name);
    }

    function categoryScopeLabel(c) {
      if (!c?.branch_id) return 'Global';
      return c.branch?.name || `Cabang #${c.branch_id}`;
    }

    function canManageCategory(c) {
      if (!c || isSystemCategory(c)) return false;
      if (isOwner.value) return true;
      if (isAdmin.value) {
        return c.branch_id != null && Number(c.branch_id) === Number(user.value?.branch_id);
      }
      return false;
    }

    function resetCategoryForm() {
      categoryForm.id = null;
      categoryForm.name = '';
      categoryForm.type = 'income';
      categoryForm.branch_id = '';
      categoryForm.is_active = true;
    }

    function editCategory(c) {
      if (!canManageCategory(c)) return;
      categoryForm.id = c.id;
      categoryForm.name = c.name;
      categoryForm.type = c.type;
      categoryForm.branch_id = c.branch_id || '';
      categoryForm.is_active = c.is_active !== false;
    }

    async function loadAccounts(branchId = null) {
      let path = '/accounts';
      const bid = branchId
        || (isAdmin.value ? user.value?.branch_id : null)
        || txForm.branch_id
        || internalTransferForm.branch_id
        || adjustmentForm.branch_id
        || transferForm.from_branch_id
        || null;
      if (isOwner.value && bid) {
        path += `?branch_id=${bid}`;
      }
      const data = await api(path);
      accounts.value = data.data || [];
      const cash = accounts.value.find((a) => a.code === 'cash');
      const defaultId = cash?.id || accounts.value[0]?.id || '';
      const ids = new Set(accounts.value.map((a) => a.id));
      if (!txForm.account_id || !ids.has(Number(txForm.account_id))) txForm.account_id = defaultId;
      if (!transferForm.account_id || !ids.has(Number(transferForm.account_id))) transferForm.account_id = defaultId;
      if (!adjustmentForm.account_id || !ids.has(Number(adjustmentForm.account_id))) adjustmentForm.account_id = defaultId;
      if (!reconForm.account_id || !ids.has(Number(reconForm.account_id))) reconForm.account_id = defaultId;
      if (!internalTransferForm.from_account_id || !ids.has(Number(internalTransferForm.from_account_id))) {
        internalTransferForm.from_account_id = defaultId;
      }
      if (internalTransferForm.to_account_id && !ids.has(Number(internalTransferForm.to_account_id))) {
        internalTransferForm.to_account_id = accounts.value.find((a) => a.id !== Number(defaultId))?.id || defaultId;
      }
    }

    async function loadAllAccounts() {
      if (!isOwner.value) {
        allAccounts.value = [];
        return;
      }
      const data = await api('/accounts?all=1');
      allAccounts.value = data.data || [];
    }

    function resetAccountForm() {
      accountForm.id = null;
      accountForm.name = '';
      accountForm.code = '';
      accountForm.is_active = true;
      const pool = isOwner.value ? allAccounts.value : accounts.value;
      accountForm.sort_order = (pool.length
        ? Math.max(...pool.map((a) => Number(a.sort_order) || 0)) + 1
        : 1);
    }

    function editAccount(a) {
      accountForm.id = a.id;
      accountForm.name = a.name || '';
      accountForm.code = a.code || '';
      accountForm.is_active = a.is_active !== false;
      accountForm.sort_order = Number(a.sort_order) || 0;
    }

    function onAccountNameInput() {
      if (accountForm.id) return;
      const raw = accountForm.name.trim().toLowerCase()
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '');
      if (raw) accountForm.code = raw.slice(0, 32);
    }

    async function submitAccount() {
      if (!accountForm.name.trim()) {
        toast('Nama akun wajib diisi.', 'error');
        return;
      }
      if (!accountForm.code.trim()) {
        toast('Kode akun wajib diisi.', 'error');
        return;
      }
      if (isAdmin.value && accountForm.id) {
        toast('Admin hanya boleh menambah akun, bukan mengubah master.', 'error');
        return;
      }
      loading.value = true;
      try {
        const payload = {
          name: accountForm.name.trim(),
          code: accountForm.code.trim().toLowerCase(),
          is_active: !!accountForm.is_active,
        };
        if (accountForm.id) {
          await api(`/accounts/${accountForm.id}`, { method: 'PUT', body: JSON.stringify(payload) });
          toast('Akun diperbarui.', 'success');
        } else {
          const res = await api('/accounts', { method: 'POST', body: JSON.stringify(payload) });
          toast(res.message || (isAdmin.value ? 'Akun dipasang ke cabang Anda.' : 'Akun ditambahkan.'), 'success');
        }
        resetAccountForm();
        if (isOwner.value) await loadAllAccounts();
        await loadAccounts(isAdmin.value ? user.value?.branch_id : null);
      } catch (_) {
      } finally {
        loading.value = false;
      }
    }

    async function deleteAccount(a) {
      if (a.in_use) {
        toast('Akun sudah digunakan. Nonaktifkan saja, jangan dihapus.', 'error');
        return;
      }
      if (!confirm(`Hapus akun "${a.name}"?`)) return;
      loading.value = true;
      try {
        await api(`/accounts/${a.id}`, { method: 'DELETE' });
        toast('Akun dihapus.', 'success');
        if (accountForm.id === a.id) resetAccountForm();
        await loadAllAccounts();
        await loadAccounts();
        await loadBranchTypes();
      } catch (_) {
      } finally {
        loading.value = false;
      }
    }

    async function loadOpeningBalances(branchId = null) {
      const bid = branchId
        || (isOwner.value ? openingForm.branch_id : user.value?.branch_id)
        || null;
      if (!bid && isOwner.value) {
        openingBalances.value = [];
        return;
      }
      const q = isOwner.value && bid ? `?branch_id=${bid}` : '';
      const data = await api(`/opening-balances${q}`);
      openingBalances.value = data.data || [];
    }

    function editOpeningForAccount(accountId, branchId = null) {
      const aid = Number(accountId);
      openingForm.account_id = aid;
      if (isOwner.value && branchId) openingForm.branch_id = Number(branchId);
      const row = openingBalances.value.find((o) => Number(o.account_id) === aid);
      if (row) {
        openingForm.amount = formatInputNumber(Math.round(Number(row.amount) || 0));
        openingForm.effective_date = String(row.effective_date).slice(0, 10);
      } else {
        openingForm.amount = '';
        openingForm.effective_date = today();
      }
    }

    async function submitOpeningBalance() {
      if (isOwner.value && !openingForm.branch_id) {
        toast('Pilih cabang.', 'error');
        return;
      }
      if (!openingForm.account_id) {
        toast('Pilih akun.', 'error');
        return;
      }
      const amount = parseInputNumber(openingForm.amount);
      if (!openingForm.effective_date) {
        toast('Tanggal mulai wajib diisi.', 'error');
        return;
      }
      loading.value = true;
      try {
        const payload = {
          account_id: Number(openingForm.account_id),
          amount,
          effective_date: openingForm.effective_date,
        };
        if (isOwner.value) payload.branch_id = Number(openingForm.branch_id);
        await api('/opening-balances', { method: 'PUT', body: JSON.stringify(payload) });
        toast('Saldo awal disimpan.', 'success');
        await loadOpeningBalances(isOwner.value ? openingForm.branch_id : user.value?.branch_id);
        await loadAccounts(isOwner.value ? openingForm.branch_id : user.value?.branch_id);
        if (page.value === 'dashboard' || page.value === 'recon' || page.value === 'branch-accounts') {
          await loadBranchDashboard(
            isOwner.value ? openingForm.branch_id : null,
            page.value === 'recon' ? reconForm.reconciliation_date : null
          );
        }
      } catch (_) {
      } finally {
        loading.value = false;
      }
    }

    function openingAmountFor(accountId) {
      const row = openingBalances.value.find((o) => Number(o.account_id) === Number(accountId));
      return row ? Number(row.amount) : null;
    }

    function openingDateFor(accountId) {
      const row = openingBalances.value.find((o) => Number(o.account_id) === Number(accountId));
      return row ? String(row.effective_date).slice(0, 10) : null;
    }

    async function onOwnerOpeningBranchChange() {
      openingForm.account_id = '';
      openingForm.amount = '';
      openingForm.effective_date = today();
      if (!openingForm.branch_id) {
        openingBalances.value = [];
        return;
      }
      await loadAccounts(openingForm.branch_id);
      await loadOpeningBalances(openingForm.branch_id);
    }

    const activeAllAccounts = computed(() =>
      allAccounts.value.filter((a) => a.is_active !== false)
    );

    function toggleAccountAssignType(id) {
      const idNum = Number(id);
      const idx = accountAssignTypeIds.value.indexOf(idNum);
      if (idx >= 0) accountAssignTypeIds.value.splice(idx, 1);
      else accountAssignTypeIds.value.push(idNum);
    }

    function toggleAccountAssignBranch(id) {
      const idNum = Number(id);
      const idx = accountAssignBranchIds.value.indexOf(idNum);
      if (idx >= 0) accountAssignBranchIds.value.splice(idx, 1);
      else accountAssignBranchIds.value.push(idNum);
    }

    async function selectAccountAssignType() {
      const t = branchTypes.value.find((x) => String(x.id) === String(accountAssignTypeId.value));
      accountAssignTypeIds.value = (t?.accounts || []).map((a) => Number(a.id));
    }

    async function saveAccountAssignType() {
      if (!accountAssignTypeId.value) {
        toast('Pilih tipe cabang dulu.', 'error');
        return;
      }
      loading.value = true;
      try {
        await api(`/branch-types/${accountAssignTypeId.value}/accounts`, {
          method: 'PUT',
          body: JSON.stringify({ account_ids: accountAssignTypeIds.value }),
        });
        toast('Akun per tipe disimpan.', 'success');
        await loadBranchTypes();
        await selectAccountAssignType();
      } catch (_) {
      } finally {
        loading.value = false;
      }
    }

    async function selectAccountAssignBranch() {
      if (!accountAssignBranchId.value) {
        accountAssignBranchMode.value = 'type';
        accountAssignBranchIds.value = [];
        accountAssignTypePreviewIds.value = [];
        return;
      }
      const data = await api(`/branches/${accountAssignBranchId.value}/account-settings`);
      const s = data.data || {};
      accountAssignBranchMode.value = s.mode || 'type';
      accountAssignBranchIds.value = (s.account_ids || []).map((id) => Number(id));
      accountAssignTypePreviewIds.value = (s.type_account_ids || []).map((id) => Number(id));
    }

    async function saveAccountAssignBranch() {
      if (!accountAssignBranchId.value) {
        toast('Pilih cabang dulu.', 'error');
        return;
      }
      loading.value = true;
      try {
        await api(`/branches/${accountAssignBranchId.value}/accounts`, {
          method: 'PUT',
          body: JSON.stringify({
            mode: accountAssignBranchMode.value,
            account_ids: accountAssignBranchMode.value === 'custom' ? accountAssignBranchIds.value : [],
          }),
        });
        toast('Akun per cabang disimpan.', 'success');
        await selectAccountAssignBranch();
        await loadAccounts(accountAssignBranchId.value);
      } catch (_) {
      } finally {
        loading.value = false;
      }
    }

    const branchSetupOpenings = reactive({});

    function clearBranchSetupOpenings() {
      Object.keys(branchSetupOpenings).forEach((k) => {
        delete branchSetupOpenings[k];
      });
    }

    function ensureBranchSetupOpening(accountId) {
      const id = String(accountId);
      if (!branchSetupOpenings[id]) {
        branchSetupOpenings[id] = { amount: '', effective_date: today() };
      }
      return branchSetupOpenings[id];
    }

    function isBranchAccountSelected(accountId) {
      return accountAssignBranchIds.value.includes(Number(accountId));
    }

    async function onBranchSetupChange() {
      openingForm.branch_id = accountAssignBranchId.value || '';
      clearBranchSetupOpenings();
      if (!accountAssignBranchId.value) {
        accountAssignBranchMode.value = 'custom';
        accountAssignBranchIds.value = [];
        accountAssignTypePreviewIds.value = [];
        openingBalances.value = [];
        return;
      }
      await selectAccountAssignBranch();
      if (accountAssignBranchMode.value === 'type') {
        accountAssignBranchIds.value = accountAssignTypePreviewIds.value.length
          ? [...accountAssignTypePreviewIds.value]
          : activeAllAccounts.value.map((a) => Number(a.id));
      }
      accountAssignBranchMode.value = 'custom';
      await loadOpeningBalances(accountAssignBranchId.value);
      for (const a of activeAllAccounts.value) {
        const row = openingBalances.value.find((o) => Number(o.account_id) === Number(a.id));
        branchSetupOpenings[String(a.id)] = {
          amount: row ? formatInputNumber(Math.round(Number(row.amount) || 0)) : '',
          effective_date: row ? String(row.effective_date).slice(0, 10) : today(),
        };
      }
    }

    function toggleBranchSetupAccount(accountId) {
      toggleAccountAssignBranch(Number(accountId));
      ensureBranchSetupOpening(accountId);
    }

    async function saveBranchSetup() {
      if (!accountAssignBranchId.value) {
        toast('Pilih cabang dulu.', 'error');
        return;
      }
      if (!accountAssignBranchIds.value.length) {
        toast('Centang minimal satu akun.', 'error');
        return;
      }
      loading.value = true;
      try {
        await api(`/branches/${accountAssignBranchId.value}/accounts`, {
          method: 'PUT',
          body: JSON.stringify({
            mode: 'custom',
            account_ids: accountAssignBranchIds.value,
          }),
        });

        for (const aid of accountAssignBranchIds.value) {
          const o = branchSetupOpenings[String(aid)];
          if (!o) continue;
          const hasAmount = String(o.amount || '').trim() !== '';
          const hadOpening = openingBalances.value.some((x) => Number(x.account_id) === Number(aid));
          if (!hasAmount && !hadOpening) continue;
          await api('/opening-balances', {
            method: 'PUT',
            body: JSON.stringify({
              branch_id: Number(accountAssignBranchId.value),
              account_id: Number(aid),
              amount: parseInputNumber(o.amount),
              effective_date: o.effective_date || today(),
            }),
          });
        }

        toast('Pengaturan cabang disimpan.', 'success');
        await onBranchSetupChange();
        await loadAccounts(accountAssignBranchId.value);
      } catch (_) {
      } finally {
        loading.value = false;
      }
    }

    async function ensureBranches() {
      const data = await api('/branches');
      branches.value = data.data || [];
      await loadBranchTypes();
    }

    async function loadBranchTypes() {
      try {
        const q = isOwner.value ? '?all=1' : '';
        const data = await api(`/branch-types${q}`);
        branchTypes.value = data.data || [];
      } catch (_) {
        branchTypes.value = [];
      }
    }

    const ownerDashScopeLabel = computed(() => {
      if (!ownerDashBranchId.value) return 'Semua cabang';
      const b = branches.value.find((x) => String(x.id) === String(ownerDashBranchId.value));
      return b?.name || 'Cabang terpilih';
    });
    const ownerDashPeriodLabel = computed(() => {
      const m = ownerDashMonths.find((x) => Number(x.value) === Number(ownerDashMonth.value));
      return `${m?.label || ownerDashMonth.value} ${ownerDashYear.value}`;
    });
    const ownerCategoryScopeLabel = ownerDashScopeLabel;

    async function loadOwnerDashboard() {
      dashLoading.value = true;
      try {
        const params = new URLSearchParams();
        if (ownerDashBranchId.value) params.set('branch_id', String(ownerDashBranchId.value));
        params.set('month', String(ownerDashMonth.value));
        params.set('year', String(ownerDashYear.value));
        const dash = await api(`/dashboard/owner?${params.toString()}`);
        ownerData.value = dash.data;
        await ensureBranches();
        await nextTick();
        renderOwnerCharts();
      } finally {
        dashLoading.value = false;
      }
    }

    async function onOwnerDashFilterChange() {
      if (page.value === 'dashboard' && isOwner.value) {
        await loadOwnerDashboard();
      }
    }
    const onOwnerDashBranchChange = onOwnerDashFilterChange;
    const onOwnerCategoryBranchChange = onOwnerDashFilterChange;

    async function loadBranchDashboard(branchId = null, asOf = null) {
      const showDashSkeleton = page.value === 'dashboard';
      if (showDashSkeleton) dashLoading.value = true;
      try {
        const params = new URLSearchParams();
        if (branchId) params.set('branch_id', String(branchId));
        const asOfDate = asOf
          || (page.value === 'recon' ? reconForm.reconciliation_date : null);
        if (asOfDate) params.set('as_of', asOfDate);
        const q = params.toString() ? `?${params.toString()}` : '';
        const dash = await api(`/dashboard/branch${q}`);
        branchData.value = dash.data;
      } finally {
        if (showDashSkeleton) dashLoading.value = false;
      }
    }

    async function loadTransfers() {
      if (isOwner.value) {
        transfers.value = ownerData.value?.transfer_pending || [];
      }
    }

    function buildTxQuery(pageNum = 1) {
      const params = new URLSearchParams();
      params.set('per_page', '20');
      params.set('page', String(pageNum));
      if (isOwner.value && txForm.branch_id) params.set('branch_id', String(txForm.branch_id));
      if (txFilter.type) params.set('type', txFilter.type);
      if (txFilter.category_id) params.set('category_id', String(txFilter.category_id));
      if (txFilter.date_from) params.set('date_from', txFilter.date_from);
      if (txFilter.date_to) params.set('date_to', txFilter.date_to);
      if (txFilter.q.trim()) params.set('q', txFilter.q.trim());
      return `/transactions?${params.toString()}`;
    }

    async function loadTransactions(pageNum = 1) {
      const data = await api(buildTxQuery(pageNum));
      const pageData = data.data;
      transactions.value = pageData?.data || (Array.isArray(pageData) ? pageData : []);
      txMeta.value = {
        total: pageData?.total ?? transactions.value.length,
        current_page: pageData?.current_page ?? 1,
        last_page: pageData?.last_page ?? 1,
        per_page: pageData?.per_page ?? 20,
      };
    }

    function applyTxFilters() {
      loadTransactions(1);
    }

    function onTxFilterTypeChange() {
      txFilter.category_id = '';
      loadTransactions(1);
    }

    function resetTxFilters() {
      txFilter.type = '';
      txFilter.category_id = '';
      txFilter.date_from = '';
      txFilter.date_to = '';
      txFilter.q = '';
      loadTransactions(1);
    }

    function onTxSearchInput() {
      clearTimeout(txSearchTimer);
      txSearchTimer = setTimeout(() => loadTransactions(1), 350);
    }

    const filterCategories = computed(() => {
      if (!txFilter.type) return categories.value;
      return categories.value.filter((c) => c.type === txFilter.type);
    });

    const reportFilterCategories = computed(() => {
      if (!reportForm.type_filter) return categories.value;
      return categories.value.filter((c) => c.type === reportForm.type_filter);
    });

    function buildReportQuery() {
      const params = new URLSearchParams();
      if (isOwner.value && reportForm.branch_id) params.set('branch_id', String(reportForm.branch_id));
      if (reportForm.date_from) params.set('date_from', reportForm.date_from);
      if (reportForm.date_to) params.set('date_to', reportForm.date_to);
      if (reportForm.type_filter) params.set('type', reportForm.type_filter);
      if (reportForm.category_id) params.set('category_id', String(reportForm.category_id));
      if (reportForm.account_id) params.set('account_id', String(reportForm.account_id));
      if (reportForm.q.trim()) params.set('q', reportForm.q.trim());
      const qs = params.toString();
      return qs ? `?${qs}` : '';
    }

    async function loadReport() {
      loading.value = true;
      try {
        const data = await api(`/reports/${reportForm.type}${buildReportQuery()}`);
        reportResult.value = data;
      } catch (_) {
        reportResult.value = null;
      } finally {
        loading.value = false;
      }
    }

    function selectReportType(id) {
      reportForm.type = id;
      reportResult.value = null;
    }

    async function exportReportPdf(disposition = 'attachment') {
      loading.value = true;
      try {
        const qs = buildReportQuery();
        const sep = qs ? '&' : '?';
        const data = await api(`/reports/${reportForm.type}/pdf-link${qs}${sep}disposition=${disposition}`);
        if (!data?.url) {
          toast('Tautan PDF tidak tersedia.', 'error');
          return;
        }

        const filename = `laporan-${reportForm.type}-${reportForm.date_from || 'dari'}-${reportForm.date_to || 'sampai'}.pdf`;

        // Buka: navigasi langsung ke signed URL (viewer native, lebih stabil dari blob).
        if (disposition === 'inline') {
          const win = window.open(data.url, '_blank');
          if (!win) {
            toast('Popup diblokir browser. Izinkan popup, atau pakai Export PDF.', 'error');
            return;
          }
          if (win) win.opener = null;
          toast('PDF dibuka di tab baru.', 'success');
          return;
        }

        // Export: unduh via blob agar nama file terkontrol.
        const res = await fetch(data.url);
        const buf = await res.arrayBuffer();
        const bytes = new Uint8Array(buf);
        const isPdf = bytes.length >= 4
          && bytes[0] === 0x25 && bytes[1] === 0x50 && bytes[2] === 0x44 && bytes[3] === 0x46; // %PDF
        if (!res.ok || !isPdf) {
          throw new Error('Gagal membuat PDF. Coba tampilkan laporan dulu, lalu export lagi.');
        }

        const blobUrl = URL.createObjectURL(new Blob([buf], { type: 'application/pdf' }));
        const a = document.createElement('a');
        a.href = blobUrl;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(() => URL.revokeObjectURL(blobUrl), 8_000);
        toast('PDF mulai diunduh.', 'success');
      } catch (e) {
        toast(e.message || 'Gagal mengunduh PDF.', 'error');
      } finally {
        loading.value = false;
      }
    }

    async function loadAdmins() {
      const data = await api('/admins');
      admins.value = data.data || [];
    }

    async function loadEmployees() {
      const params = new URLSearchParams();
      if (employeeFilter.branch_id) params.set('branch_id', String(employeeFilter.branch_id));
      if (employeeFilter.status) params.set('status', employeeFilter.status);
      if (employeeFilter.q.trim()) params.set('q', employeeFilter.q.trim());
      const qs = params.toString();
      const data = await api(`/employees${qs ? `?${qs}` : ''}`);
      employees.value = data.data || [];
    }

    async function loadClosingBoard() {
      if (!canAccessClosings.value) return;
      const params = new URLSearchParams();
      params.set('year', String(closingFilter.year));
      params.set('month', String(closingFilter.month));
      if (isOwner.value && closingFilter.branch_id) {
        params.set('branch_id', String(closingFilter.branch_id));
      }
      const data = await api(`/closings/board?${params.toString()}`);
      closingBoard.value = {
        meta: data.meta || null,
        data: data.data || [],
        groups: data.groups || [],
      };
    }

    function attendanceShort(status) {
      const map = { present: 'H', leave: 'I', sick: 'S', absent: 'A' };
      return map[status] || '';
    }

    function attendanceCellClass(status) {
      if (!status) return '';
      return `att-cell att-${status}`;
    }

    function attendanceBoardDate(day) {
      const y = Number(attendanceFilter.year);
      const m = String(Number(attendanceFilter.month)).padStart(2, '0');
      const d = String(Number(day)).padStart(2, '0');
      return `${y}-${m}-${d}`;
    }

    function recomputeAttendanceRowCounts(row) {
      const counts = { present: 0, leave: 0, sick: 0, absent: 0 };
      const daily = row.daily || {};
      for (const key of Object.keys(daily)) {
        const st = daily[key];
        if (st && counts[st] != null) counts[st] += 1;
      }
      row.counts = counts;
    }

    function recomputeAttendanceBoardTotals() {
      const groups = attendanceBoard.value.groups || [];
      for (const g of groups) {
        g.counts = {
          present: (g.rows || []).reduce((s, r) => s + Number(r.counts?.present || 0), 0),
          leave: (g.rows || []).reduce((s, r) => s + Number(r.counts?.leave || 0), 0),
          sick: (g.rows || []).reduce((s, r) => s + Number(r.counts?.sick || 0), 0),
          absent: (g.rows || []).reduce((s, r) => s + Number(r.counts?.absent || 0), 0),
        };
      }
      if (attendanceBoard.value.meta) {
        attendanceBoard.value.meta.counts = {
          present: groups.reduce((s, g) => s + Number(g.counts?.present || 0), 0),
          leave: groups.reduce((s, g) => s + Number(g.counts?.leave || 0), 0),
          sick: groups.reduce((s, g) => s + Number(g.counts?.sick || 0), 0),
          absent: groups.reduce((s, g) => s + Number(g.counts?.absent || 0), 0),
        };
      }
    }

    async function onAttendanceBoardCellChange(row, day, event) {
      if (!isOwner.value) return;
      const next = event?.target?.value || '';
      const prev = row.daily?.[day] || '';
      if (String(next) === String(prev || '')) return;

      if (!row.daily) row.daily = {};
      row.daily[day] = next || null;
      recomputeAttendanceRowCounts(row);
      recomputeAttendanceBoardTotals();

      loading.value = true;
      try {
        await api('/attendance/cell', {
          method: 'PUT',
          body: JSON.stringify({
            employee_id: Number(row.employee_id),
            date: attendanceBoardDate(day),
            status: next || null,
          }),
        });
        toast(`Absensi ${row.name} tgl ${day} disimpan.`, 'success');
      } catch (_) {
        row.daily[day] = prev || null;
        recomputeAttendanceRowCounts(row);
        recomputeAttendanceBoardTotals();
      } finally {
        loading.value = false;
      }
    }

    async function loadAttendanceDaily() {
      if (!canAccessAttendance.value) return;
      const params = new URLSearchParams();
      params.set('date', attendanceDailyDate.value);
      if (isOwner.value && attendanceFilter.branch_id) {
        params.set('branch_id', String(attendanceFilter.branch_id));
      }
      const data = await api(`/attendance/daily?${params.toString()}`);
      attendanceDailyRows.value = (data.data || []).map((row) => ({
        ...row,
        status: row.status || '',
        note: row.note || '',
      }));
      attendanceDailyMeta.value = data.meta || null;
    }

    async function loadAttendanceBoard() {
      if (!canAccessAttendance.value) return;
      const params = new URLSearchParams();
      params.set('year', String(attendanceFilter.year));
      params.set('month', String(attendanceFilter.month));
      if (isOwner.value && attendanceFilter.branch_id) {
        params.set('branch_id', String(attendanceFilter.branch_id));
      }
      const data = await api(`/attendance/board?${params.toString()}`);
      attendanceBoard.value = {
        meta: data.meta || null,
        data: data.data || [],
        groups: data.groups || [],
      };
    }

    async function onAttendanceFilterChange() {
      if (attendanceTab.value === 'daily') await loadAttendanceDaily();
      else await loadAttendanceBoard();
    }

    async function switchAttendanceTab(tab) {
      attendanceTab.value = tab;
      if (tab === 'daily') await loadAttendanceDaily();
      else await loadAttendanceBoard();
    }

    function markAllAttendancePresent() {
      for (const row of attendanceDailyRows.value) {
        row.status = 'present';
      }
      toast('Semua ditandai Hadir. Klik Simpan untuk menyimpan.', 'info');
    }

    async function copyYesterdayAttendance() {
      if (!attendanceDailyRows.value.length) return;
      const d = new Date(`${attendanceDailyDate.value}T12:00:00`);
      d.setDate(d.getDate() - 1);
      const yest = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
      loading.value = true;
      try {
        const params = new URLSearchParams();
        params.set('date', yest);
        if (isOwner.value && attendanceFilter.branch_id) {
          params.set('branch_id', String(attendanceFilter.branch_id));
        }
        const data = await api(`/attendance/daily?${params.toString()}`);
        const byId = new Map((data.data || []).map((r) => [r.employee_id, r]));
        let copied = 0;
        for (const row of attendanceDailyRows.value) {
          const prev = byId.get(row.employee_id);
          if (prev?.status) {
            row.status = prev.status;
            row.note = prev.note || '';
            copied++;
          }
        }
        toast(copied ? `Disalin ${copied} status dari ${yest}.` : `Kemarin (${yest}) belum ada absensi.`, copied ? 'success' : 'info');
      } catch (_) {
        toast('Gagal menyalin absensi kemarin.', 'error');
      } finally {
        loading.value = false;
      }
    }

    async function saveAttendanceDaily() {
      if (!attendanceDailyRows.value.length) {
        toast('Tidak ada karyawan untuk diabsen.', 'error');
        return;
      }
      const incomplete = attendanceDailyRows.value.filter((r) => !r.status);
      if (incomplete.length) {
        toast(`Masih ada ${incomplete.length} karyawan tanpa status.`, 'error');
        return;
      }
      const c = attendanceDailyCounts.value;
      const detail = `Hadir ${c.present} · Izin ${c.leave} · Sakit ${c.sick} · Alpha ${c.absent}`;
      const ok = await askClosingConfirm({
        title: 'Simpan Absensi',
        message: `Simpan absensi tanggal ${attendanceDailyDate.value} untuk ${attendanceDailyRows.value.length} karyawan?`,
        detail,
        confirmLabel: 'Simpan',
        danger: false,
      });
      if (!ok) {
        toast('Penyimpanan dibatalkan.', 'info');
        return;
      }
      loading.value = true;
      try {
        const payload = {
          date: attendanceDailyDate.value,
          items: attendanceDailyRows.value.map((r) => ({
            employee_id: r.employee_id,
            status: r.status,
            note: r.note || null,
          })),
        };
        if (isOwner.value && attendanceFilter.branch_id) {
          payload.branch_id = Number(attendanceFilter.branch_id);
        }
        await api('/attendance/daily', {
          method: 'PUT',
          body: JSON.stringify(payload),
        });
        toast(`Absensi ${attendanceDailyDate.value} disimpan. ${detail}`, 'success');
        await loadAttendanceDaily();
      } catch (_) {
        toast('Gagal menyimpan absensi.', 'error');
      } finally {
        loading.value = false;
      }
    }

    function recomputePayrollRowTotal(row) {
      const total = Number(row.gapok || 0)
        + Number(row.insentif_hp || 0)
        + Number(row.service_incentive || 0)
        + Number(row.insentif_acc || 0)
        + Number(row.bonus_absen || 0)
        - Number(row.hutang || 0)
        - Number(row.pengeluaran || 0);
      row.total = Math.round(total * 100) / 100;
    }

    function onPayrollManualInput(row, field, e) {
      if (row.status === 'locked') return;
      row[field] = parseInputNumber(e.target.value);
      e.target.value = formatInputNumber(row[field]);
      recomputePayrollRowTotal(row);
    }

    function onPayrollFocus(e) {
      e.target.dataset.orig = e.target.value;
    }

    function onPayrollKeydown(e) {
      if (e.key === 'Escape') {
        e.preventDefault();
        e.target.value = e.target.dataset.orig ?? '';
        e.target.blur();
      }
      if (e.key === 'Enter') {
        e.preventDefault();
        e.target.blur();
      }
    }

    async function loadPayrollBoard() {
      if (!canAccessPayroll.value) return;
      const params = new URLSearchParams();
      params.set('year', String(payrollFilter.year));
      params.set('month', String(payrollFilter.month));
      if (isOwner.value && payrollFilter.branch_id) {
        params.set('branch_id', String(payrollFilter.branch_id));
      }
      const data = await api(`/payrolls/board?${params.toString()}`);
      payrollBoard.value = {
        meta: data.meta || null,
        data: data.data || [],
        groups: data.groups || [],
      };
    }

    async function onPayrollFilterChange() {
      await loadPayrollBoard();
    }

    async function savePayrollBoard() {
      const allRows = payrollBoard.value.data || [];
      const rows = allRows.filter((r) => r.status !== 'locked');
      if (!allRows.length) {
        toast('Tidak ada karyawan untuk digaji.', 'error');
        return;
      }
      if (!rows.length) {
        toast('Semua slip sudah terkunci. Buka kunci dulu untuk mengubah.', 'error');
        return;
      }
      const grand = rows.reduce((s, r) => s + Number(r.total || 0), 0);
      const ok = await askClosingConfirm({
        title: 'Simpan Gaji',
        message: `Simpan rekap gaji ${payrollMonthLabel.value} untuk ${rows.length} karyawan (draf)?`,
        detail: `Total bersih draf: ${formatRp(grand)}`,
        confirmLabel: 'Simpan',
        danger: false,
      });
      if (!ok) {
        toast('Penyimpanan dibatalkan.', 'info');
        return;
      }
      loading.value = true;
      try {
        const payload = {
          year: Number(payrollFilter.year),
          month: Number(payrollFilter.month),
          items: rows.map((r) => ({
            employee_id: r.employee_id,
            insentif_acc: Number(r.insentif_acc || 0),
            bonus_absen: Number(r.bonus_absen || 0),
            hutang: Number(r.hutang || 0),
            pengeluaran: Number(r.pengeluaran || 0),
            note: r.note || null,
          })),
        };
        if (isOwner.value && payrollFilter.branch_id) {
          payload.branch_id = Number(payrollFilter.branch_id);
        }
        await api('/payrolls/save', {
          method: 'PUT',
          body: JSON.stringify(payload),
        });
        toast(`Gaji ${payrollMonthLabel.value} disimpan.`, 'success');
        await loadPayrollBoard();
      } catch (_) {
        toast('Gagal menyimpan gaji.', 'error');
      } finally {
        loading.value = false;
      }
    }

    async function lockPayrollBoard() {
      const rows = payrollBoard.value.data || [];
      if (!rows.length) {
        toast('Tidak ada karyawan untuk dikunci.', 'error');
        return;
      }
      const grand = payrollBoard.value.meta?.totals?.grand_total
        ?? rows.reduce((s, r) => s + Number(r.total || 0), 0);
      const ok = await askClosingConfirm({
        title: 'Kunci Gaji',
        message: `Kunci rekap gaji ${payrollMonthLabel.value}? Angka otomatis akan dibekukan.`,
        detail: `${rows.length} karyawan · Total ${formatRp(grand)}`,
        confirmLabel: 'Kunci',
        danger: true,
      });
      if (!ok) {
        toast('Penguncian dibatalkan.', 'info');
        return;
      }
      loading.value = true;
      try {
        const payload = {
          year: Number(payrollFilter.year),
          month: Number(payrollFilter.month),
        };
        if (isOwner.value && payrollFilter.branch_id) {
          payload.branch_id = Number(payrollFilter.branch_id);
        }
        await api('/payrolls/lock', {
          method: 'POST',
          body: JSON.stringify(payload),
        });
        toast(`Gaji ${payrollMonthLabel.value} dikunci.`, 'success');
        await loadPayrollBoard();
      } catch (_) {
        toast('Gagal mengunci gaji.', 'error');
      } finally {
        loading.value = false;
      }
    }

    async function unlockPayrollBoard() {
      if (!isOwner.value) return;
      const ok = await askClosingConfirm({
        title: 'Buka Kunci Gaji',
        message: `Buka kunci gaji ${payrollMonthLabel.value}? Slip kembali ke draf.`,
        detail: 'Setelah dibuka, hitung ulang mengikuti absensi/closing/service terbaru.',
        confirmLabel: 'Buka Kunci',
        danger: true,
      });
      if (!ok) {
        toast('Pembukaan kunci dibatalkan.', 'info');
        return;
      }
      loading.value = true;
      try {
        const payload = {
          year: Number(payrollFilter.year),
          month: Number(payrollFilter.month),
        };
        if (payrollFilter.branch_id) {
          payload.branch_id = Number(payrollFilter.branch_id);
        }
        await api('/payrolls/unlock', {
          method: 'POST',
          body: JSON.stringify(payload),
        });
        toast(`Kunci gaji ${payrollMonthLabel.value} dibuka.`, 'success');
        await loadPayrollBoard();
      } catch (_) {
        toast('Gagal membuka kunci gaji.', 'error');
      } finally {
        loading.value = false;
      }
    }

    async function openPayrollDetail(row) {
      payrollDetail.open = true;
      payrollDetail.loading = true;
      payrollDetail.data = null;
      payrollDetail.services = [];
      payrollDetail.meta = null;
      try {
        const params = new URLSearchParams();
        params.set('employee_id', String(row.employee_id));
        params.set('year', String(payrollFilter.year));
        params.set('month', String(payrollFilter.month));
        const data = await api(`/payrolls/detail?${params.toString()}`);
        payrollDetail.data = data.data || null;
        payrollDetail.services = data.services || [];
        payrollDetail.meta = data.meta || null;
      } catch (_) {
        toast('Gagal memuat detail gaji.', 'error');
        payrollDetail.open = false;
      } finally {
        payrollDetail.loading = false;
      }
    }

    function closePayrollDetail() {
      payrollDetail.open = false;
    }

    function wwBranchParams(extra = {}) {
      const params = new URLSearchParams(extra);
      if (isOwner.value) {
        if (!wwFilter.branch_id) return null;
        params.set('branch_id', String(wwFilter.branch_id));
      }
      return params;
    }

    async function ensureWwBranch() {
      if (isOwner.value && !wwFilter.branch_id) {
        if (workshopBranches.value.length === 1) {
          wwFilter.branch_id = workshopBranches.value[0].id;
        } else {
          return false;
        }
      }
      return true;
    }

    async function loadWorkshopWagePage() {
      if (!canAccessWorkshopWages.value) return;
      if (!(await ensureWwBranch())) {
        wwJobs.value = [];
        wwWeeks.value = [];
        wwWeekDetail.value = null;
        return;
      }
      await Promise.all([
        loadWwTechnicians(),
        loadWwJobTypes(),
        loadWwSettings(),
      ]);
      if (wwTab.value === 'daily') await loadWwDailyJobs();
      else if (wwTab.value === 'weekly') await loadWwWeeksAndDetail();
      else await loadWwSettings();
    }

    async function switchWwTab(tab) {
      wwTab.value = tab;
      await loadWorkshopWagePage();
    }

    async function onWwFilterChange() {
      await loadWorkshopWagePage();
    }

    async function loadWwTechnicians() {
      const params = wwBranchParams();
      if (!params) return;
      const data = await api(`/workshop-wages/technicians?${params.toString()}`);
      wwTechnicians.value = data.data || [];
      if (!wwJobForm.employee_id && wwTechnicians.value.length) {
        wwJobForm.employee_id = wwTechnicians.value[0].id;
      }
    }

    async function loadWwJobTypes() {
      const params = wwBranchParams();
      if (!params) return;
      const data = await api(`/workshop-wages/job-types?${params.toString()}`);
      wwJobTypes.value = data.data || [];
    }

    async function loadWwSettings() {
      const params = wwBranchParams({
        year: String(wwFilter.year),
        month: String(wwFilter.month),
      });
      if (!params) {
        wwSettingsRows.value = [];
        wwSettingsMeta.value = null;
        return;
      }
      const data = await api(`/workshop-wages/settings?${params.toString()}`);
      wwSettingsRows.value = (data.data || []).map((r) => ({
        ...r,
        tech_share_pct: Number(r.tech_share_pct ?? 50),
      }));
      wwSettingsMeta.value = data.meta || null;
    }

    async function saveWwSettings() {
      if (!(await ensureWwBranch())) {
        toast('Pilih cabang bengkel dulu.', 'error');
        return;
      }
      const rows = wwSettingsRows.value || [];
      if (!rows.length) {
        toast('Tidak ada teknisi untuk diatur.', 'error');
        return;
      }
      for (const r of rows) {
        const pct = Number(r.tech_share_pct);
        if (Number.isNaN(pct) || pct < 0 || pct > 100) {
          toast(`Persen ${r.name} harus 0–100.`, 'error');
          return;
        }
      }
      const ok = await askClosingConfirm({
        title: 'Simpan Persen Per Teknisi',
        message: `Simpan bagian teknisi untuk ${rows.length} orang (bulan ${wwFilter.month}/${wwFilter.year})?`,
        detail: 'Setiap teknisi bisa punya persen berbeda.',
        confirmLabel: 'Simpan',
      });
      if (!ok) return;
      loading.value = true;
      try {
        const payload = {
          year: Number(wwFilter.year),
          month: Number(wwFilter.month),
          items: rows.map((r) => ({
            employee_id: r.employee_id,
            tech_share_pct: Number(r.tech_share_pct),
          })),
        };
        if (isOwner.value) payload.branch_id = Number(wwFilter.branch_id);
        const data = await api('/workshop-wages/settings', {
          method: 'PUT',
          body: JSON.stringify(payload),
        });
        wwSettingsRows.value = (data.data || []).map((r) => ({
          ...r,
          tech_share_pct: Number(r.tech_share_pct ?? 50),
        }));
        wwSettingsMeta.value = data.meta || null;
        toast('Persen per teknisi disimpan.', 'success');
        if (wwTab.value === 'weekly') await loadWwWeeksAndDetail();
      } catch (_) {
        toast('Gagal menyimpan persen.', 'error');
      } finally {
        loading.value = false;
      }
    }

    async function loadWwDailyJobs() {
      const params = wwBranchParams({ date: wwDailyDate.value });
      if (!params) {
        wwJobs.value = [];
        return;
      }
      const data = await api(`/workshop-wages/jobs?${params.toString()}`);
      wwJobs.value = data.data || [];
    }

    function resetWwJobForm() {
      wwJobForm.id = null;
      wwJobForm.employee_id = wwTechnicians.value[0]?.id || '';
      wwJobForm.job_date = wwDailyDate.value || today();
      wwJobForm.job_type = 'ONGKER';
      wwJobForm.amount = '';
      wwJobForm.note = '';
    }

    function editWwJob(job) {
      wwJobForm.id = job.id;
      wwJobForm.employee_id = job.employee_id;
      wwJobForm.job_date = job.job_date;
      wwJobForm.job_type = job.job_type;
      wwJobForm.amount = formatInputNumber(job.amount);
      wwJobForm.note = job.note || '';
      toast('Data dimuat ke form.', 'info');
    }

    async function submitWwJob() {
      if (!(await ensureWwBranch())) {
        toast('Pilih cabang bengkel dulu.', 'error');
        return;
      }
      if (!wwJobForm.employee_id || !wwJobForm.job_type.trim() || !wwJobForm.job_date) {
        toast('Lengkapi teknisi, jenis kerja, dan tanggal.', 'error');
        return;
      }
      const amount = parseInputNumber(wwJobForm.amount);
      if (!amount) {
        toast('Nominal wajib diisi.', 'error');
        return;
      }
      loading.value = true;
      try {
        const payload = {
          employee_id: Number(wwJobForm.employee_id),
          job_date: wwJobForm.job_date,
          job_type: wwJobForm.job_type.trim(),
          amount,
          note: wwJobForm.note.trim() || null,
        };
        if (isOwner.value) payload.branch_id = Number(wwFilter.branch_id);
        if (wwJobForm.id) {
          await api(`/workshop-wages/jobs/${wwJobForm.id}`, {
            method: 'PUT',
            body: JSON.stringify(payload),
          });
          toast('Kerja diperbarui.', 'success');
        } else {
          await api('/workshop-wages/jobs', {
            method: 'POST',
            body: JSON.stringify(payload),
          });
          toast('Kerja ditambah.', 'success');
        }
        wwDailyDate.value = wwJobForm.job_date;
        resetWwJobForm();
        await loadWwDailyJobs();
        await loadWwJobTypes();
      } catch (_) {
        toast('Gagal menyimpan kerja.', 'error');
      } finally {
        loading.value = false;
      }
    }

    async function deleteWwJob(job) {
      const ok = await askClosingConfirm({
        title: 'Hapus Kerja',
        message: `Hapus ${job.job_type} — ${job.employee_name}?`,
        detail: formatRp(job.amount),
        confirmLabel: 'Hapus',
        danger: true,
      });
      if (!ok) return;
      loading.value = true;
      try {
        await api(`/workshop-wages/jobs/${job.id}`, { method: 'DELETE' });
        toast('Kerja dihapus.', 'success');
        if (wwJobForm.id === job.id) resetWwJobForm();
        await loadWwDailyJobs();
      } catch (_) {
        toast('Gagal menghapus kerja.', 'error');
      } finally {
        loading.value = false;
      }
    }

    async function loadWwWeeksAndDetail() {
      const params = wwBranchParams({
        year: String(wwFilter.year),
        month: String(wwFilter.month),
      });
      if (!params) {
        wwWeeks.value = [];
        wwWeekDetail.value = null;
        return;
      }
      const data = await api(`/workshop-wages/weeks?${params.toString()}`);
      wwWeeks.value = data.data || [];
      wwMeta.value = data.meta || null;
      if (data.meta?.previous_week_start && !wwFilter.week_start) {
        wwFilter.week_start = data.meta.previous_week_start;
      }
      const exists = wwWeeks.value.some((w) => w.week_start === wwFilter.week_start);
      if (!exists && wwWeeks.value.length) {
        const prev = data.meta?.previous_week_start;
        const match = wwWeeks.value.find((w) => w.week_start === prev);
        wwFilter.week_start = match ? match.week_start : wwWeeks.value[0].week_start;
      }
      await loadWwWeekDetail();
    }

    async function loadWwWeekDetail() {
      if (!wwFilter.week_start) {
        wwWeekDetail.value = null;
        return;
      }
      const params = wwBranchParams({ week_start: wwFilter.week_start });
      if (!params) {
        wwWeekDetail.value = null;
        return;
      }
      const data = await api(`/workshop-wages/weeks/detail?${params.toString()}`);
      wwWeekDetail.value = data.data || null;
    }

    async function onWwWeekSelect() {
      await loadWwWeekDetail();
    }

    async function payWwWeek() {
      if (!wwWeekDetail.value) return;
      if (wwWeekDetail.value.status === 'paid') {
        toast('Minggu ini sudah lunas.', 'info');
        return;
      }
      const d = wwWeekDetail.value;
      const ok = await askClosingConfirm({
        title: 'Tandai Lunas',
        message: `Tandai lunas ${d.label}?`,
        detail: `Upah teknisi ${formatRp(d.totals?.tech_net || 0)} · Bagian bengkel ${formatRp(d.totals?.shop_share || 0)}`,
        confirmLabel: 'Lunas',
        danger: false,
      });
      if (!ok) return;
      loading.value = true;
      try {
        const payload = { week_start: d.week_start };
        if (isOwner.value) payload.branch_id = Number(wwFilter.branch_id);
        await api('/workshop-wages/weeks/pay', {
          method: 'POST',
          body: JSON.stringify(payload),
        });
        toast('Minggu ditandai lunas.', 'success');
        await loadWwWeeksAndDetail();
      } catch (_) {
        toast('Gagal menandai lunas.', 'error');
      } finally {
        loading.value = false;
      }
    }

    async function reopenWwWeek() {
      if (!isOwner.value || !wwWeekDetail.value) return;
      const d = wwWeekDetail.value;
      const ok = await askClosingConfirm({
        title: 'Buka Minggu Lunas',
        message: `Buka kembali ${d.label}?`,
        detail: 'Data kerja minggu itu bisa diubah lagi.',
        confirmLabel: 'Buka',
        danger: true,
      });
      if (!ok) return;
      loading.value = true;
      try {
        await api('/workshop-wages/weeks/reopen', {
          method: 'POST',
          body: JSON.stringify({
            week_start: d.week_start,
            branch_id: Number(wwFilter.branch_id),
          }),
        });
        toast('Minggu dibuka kembali.', 'success');
        await loadWwWeeksAndDetail();
      } catch (_) {
        toast('Gagal membuka minggu.', 'error');
      } finally {
        loading.value = false;
      }
    }

    async function onClosingFilterChange() {
      await loadClosingBoard();
    }

    async function saveClosingDaily(row, day, rawValue, inputEl = null) {
      const qty = Math.max(0, Math.min(999, Number(String(rawValue).replace(/\D/g, '') || 0)));
      const prev = Number(row.daily?.[day] || 0);
      if (qty === prev) return;

      const confirmMsg = qty === 0
        ? `Hapus closing ${row.name} tanggal ${day}?`
        : `Simpan closing ${row.name} tanggal ${day}?`;
      const detail = qty === 0 ? `Nilai sekarang: ${prev}` : `${prev || 0} → ${qty}`;
      const ok = await askClosingConfirm({
        title: qty === 0 ? 'Hapus Closingan' : 'Simpan Closingan',
        message: confirmMsg,
        detail,
        confirmLabel: qty === 0 ? 'Hapus' : 'Simpan',
        danger: qty === 0,
      });
      if (!ok) {
        if (inputEl) inputEl.value = prev || '';
        toast('Perubahan dibatalkan.', 'info');
        return;
      }

      loading.value = true;
      try {
        const date = `${closingFilter.year}-${String(closingFilter.month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
        await api('/closings/daily', {
          method: 'PUT',
          body: JSON.stringify({
            employee_id: row.employee_id,
            closing_date: date,
            qty,
          }),
        });
        toast(qty === 0 ? `Closing ${row.name} tgl ${day} dihapus.` : `Closing ${row.name} tgl ${day} disimpan: ${qty}.`, 'success');
        await loadClosingBoard();
      } catch (_) {
        if (inputEl) inputEl.value = prev || '';
        toast('Gagal menyimpan closingan.', 'error');
        await loadClosingBoard();
      } finally {
        loading.value = false;
      }
    }

    async function saveClosingTarget(row, rawValue, inputEl = null) {
      const target = Math.max(0, Math.min(9999, Number(String(rawValue).replace(/\D/g, '') || 0)));
      const prev = Number(row.target || 0);
      if (target === prev) return;

      const ok = await askClosingConfirm({
        title: 'Ubah Target',
        message: `Ubah target ${row.name}?`,
        detail: `${prev || 0} → ${target}`,
        confirmLabel: 'Simpan',
        danger: false,
      });
      if (!ok) {
        if (inputEl) inputEl.value = prev || '';
        toast('Perubahan dibatalkan.', 'info');
        return;
      }

      loading.value = true;
      try {
        await api('/closings/targets', {
          method: 'PUT',
          body: JSON.stringify({
            employee_id: row.employee_id,
            year: Number(closingFilter.year),
            month: Number(closingFilter.month),
            target,
          }),
        });
        toast(`Target ${row.name} disimpan: ${target}.`, 'success');
        await loadClosingBoard();
      } catch (_) {
        if (inputEl) inputEl.value = prev || '';
        toast('Gagal menyimpan target.', 'error');
        await loadClosingBoard();
      } finally {
        loading.value = false;
      }
    }

    function onClosingFocus(e) {
      e.target.dataset.orig = e.target.value;
    }

    function onClosingKeydown(e) {
      if (e.key === 'Escape') {
        e.preventDefault();
        e.target.value = e.target.dataset.orig ?? '';
        e.target.blur();
        toast('Perubahan dibatalkan.', 'info');
      }
      if (e.key === 'Enter') {
        e.preventDefault();
        e.target.blur();
      }
    }

    function closingPctClass(pct) {
      if (pct == null) return '';
      if (pct >= 100) return 'value-income';
      if (pct >= 70) return '';
      return 'value-expense';
    }

    function resetEmployeeForm() {
      employeeForm.id = null;
      employeeForm.branch_id = '';
      employeeForm.name = '';
      employeeForm.phone = '';
      employeeForm.positions = [];
      employeeForm.status = 'active';
      employeeForm.joined_at = '';
      employeeForm.notes = '';
    }

    function toggleEmployeePosition(code) {
      const idx = employeeForm.positions.indexOf(code);
      if (idx >= 0) {
        employeeForm.positions.splice(idx, 1);
      } else {
        employeeForm.positions.push(code);
      }
    }

    function formatEmployeePositions(emp) {
      if (!emp) return '—';
      if (emp.position) return emp.position;
      const codes = Array.isArray(emp.positions) ? emp.positions : [];
      if (!codes.length) return '—';
      const labels = employeePositionOptions
        .filter((o) => codes.includes(o.value))
        .map((o) => o.label);
      return labels.length ? labels.join(', ') : '—';
    }

    function scrollMainTop(selector) {
      const scroller = document.querySelector('.main-scroll');
      if (scroller) {
        scroller.scrollTo({ top: 0, behavior: 'smooth' });
      } else {
        window.scrollTo({ top: 0, behavior: 'smooth' });
      }
      if (selector) {
        const el = document.querySelector(selector);
        if (el) {
          requestAnimationFrame(() => {
            el.scrollIntoView({ behavior: 'smooth', block: 'start' });
          });
        }
      }
    }

    function editEmployee(emp) {
      const branchId = emp.branch_id ?? emp.branch?.id;
      employeeForm.id = emp.id;
      employeeForm.branch_id = branchId != null && branchId !== '' ? Number(branchId) : '';
      employeeForm.name = emp.name || '';
      employeeForm.phone = emp.phone || '';
      employeeForm.positions = Array.isArray(emp.positions) ? [...emp.positions] : [];
      employeeForm.status = emp.status || 'active';
      employeeForm.joined_at = emp.joined_at ? String(emp.joined_at).slice(0, 10) : '';
      employeeForm.notes = emp.notes || '';
      scrollMainTop('#employee-form-card');
      toast('Data dimuat ke form. Ubah lalu klik Perbarui.', 'success');
    }

    async function submitEmployee() {
      if (!employeeForm.branch_id || !employeeForm.name.trim() || !employeeForm.phone.trim()) {
        toast('Cabang, nama, dan nomor telepon wajib diisi.', 'error');
        return;
      }
      loading.value = true;
      try {
        const payload = {
          branch_id: Number(employeeForm.branch_id),
          name: employeeForm.name.trim(),
          phone: employeeForm.phone.trim(),
          positions: [...employeeForm.positions],
          status: employeeForm.status,
          joined_at: employeeForm.joined_at || null,
          notes: employeeForm.notes.trim() || null,
        };
        if (employeeForm.id) {
          await api(`/employees/${employeeForm.id}`, { method: 'PUT', body: JSON.stringify(payload) });
          toast('Karyawan diperbarui.', 'success');
        } else {
          await api('/employees', { method: 'POST', body: JSON.stringify(payload) });
          toast('Karyawan ditambahkan.', 'success');
        }
        resetEmployeeForm();
        await loadEmployees();
      } catch (_) {
      } finally {
        loading.value = false;
      }
    }

    async function deleteEmployee(id) {
      if (!confirm('Hapus karyawan ini?')) return;
      loading.value = true;
      try {
        await api(`/employees/${id}`, { method: 'DELETE' });
        toast('Karyawan dihapus.', 'success');
        if (employeeForm.id === id) resetEmployeeForm();
        await loadEmployees();
      } catch (_) {
      } finally {
        loading.value = false;
      }
    }

    function buildServiceQuery() {
      const params = new URLSearchParams();
      if (isOwner.value && serviceFilter.branch_id) params.set('branch_id', String(serviceFilter.branch_id));
      if (serviceFilter.employee_id) params.set('employee_id', String(serviceFilter.employee_id));
      if (serviceFilter.date_from) params.set('date_from', serviceFilter.date_from);
      if (serviceFilter.date_to) params.set('date_to', serviceFilter.date_to);
      if (serviceFilter.q.trim()) params.set('q', serviceFilter.q.trim());
      const qs = params.toString();
      return qs ? `?${qs}` : '';
    }

    function employeePositionsList(emp) {
      if (!emp) return [];
      let raw = emp.positions;
      if (typeof raw === 'string') {
        try { raw = JSON.parse(raw); } catch (_) { raw = []; }
      }
      if (Array.isArray(raw)) {
        return raw.map((p) => String(p).toLowerCase());
      }
      return [];
    }

    function isTechnicianEmployee(emp) {
      if (!emp) return false;
      if (employeePositionsList(emp).includes('teknisi')) return true;
      const label = String(emp.position || '').toLowerCase();
      return label.split(',').some((part) => part.trim().includes('teknisi'));
    }

    async function loadServiceTechnicians() {
      if (!canInputService.value && !isOwner.value) {
        serviceTechnicians.value = [];
        return;
      }
      try {
        const params = new URLSearchParams();
        if (isOwner.value && serviceFilter.branch_id) {
          params.set('branch_id', String(serviceFilter.branch_id));
        }
        const qs = params.toString();
        const data = await api(`/service-records/technicians${qs ? `?${qs}` : ''}`);
        // Endpoint sudah filter teknisi + cabang; jangan filter ulang ketat di client
        // (positions JSON kadang string / label lama "Teknisi").
        serviceTechnicians.value = (data.data || []).filter((e) => (
          e.status === 'active' || isOwner.value || isTechnicianEmployee(e)
        ));
      } catch (_) {
        serviceTechnicians.value = [];
      }
    }

    async function loadServiceRecords() {
      const data = await api(`/service-records${buildServiceQuery()}`);
      serviceRecords.value = data.data || [];
      serviceSummary.value = data.summary || { jumlah: 0, total_modal: 0, total_harga: 0, total_profit: 0 };
      await loadServiceTechnicians();
    }

    function resetServiceForm() {
      serviceForm.id = null;
      serviceForm.employee_id = '';
      serviceForm.service_date = today();
      serviceForm.brand = '';
      serviceForm.device_type = '';
      serviceForm.damage = '';
      serviceForm.cost = '';
      serviceForm.price = '';
      serviceForm.notes = '';
    }

    function editService(row) {
      serviceForm.id = row.id;
      serviceForm.employee_id = row.employee_id || row.employee?.id || '';
      serviceForm.service_date = (row.service_date || '').toString().slice(0, 10);
      serviceForm.brand = row.brand || '';
      serviceForm.device_type = row.device_type || '';
      serviceForm.damage = row.damage || '';
      serviceForm.cost = formatInputNumber(row.cost);
      serviceForm.price = formatInputNumber(row.price);
      serviceForm.notes = row.notes || '';
      scrollMainTop('#service-form-card');
    }

    async function submitService() {
      const cost = parseInputNumber(serviceForm.cost);
      const price = parseInputNumber(serviceForm.price);
      if (!serviceForm.employee_id || !serviceForm.brand.trim() || !serviceForm.device_type.trim() || !serviceForm.damage.trim()) {
        toast('Lengkapi teknisi, merek, type, dan kerusakan.', 'error');
        return;
      }
      if (price < 0 || cost < 0) {
        toast('Modal dan harga tidak valid.', 'error');
        return;
      }
      loading.value = true;
      try {
        const payload = {
          employee_id: Number(serviceForm.employee_id),
          service_date: serviceForm.service_date,
          brand: serviceForm.brand.trim(),
          device_type: serviceForm.device_type.trim(),
          damage: serviceForm.damage.trim(),
          cost,
          price,
          notes: serviceForm.notes.trim() || null,
        };
        if (serviceForm.id) {
          await api(`/service-records/${serviceForm.id}`, { method: 'PUT', body: JSON.stringify(payload) });
          toast('Catatan servis diperbarui.', 'success');
        } else {
          await api('/service-records', { method: 'POST', body: JSON.stringify(payload) });
          toast('Catatan servis disimpan.', 'success');
        }
        resetServiceForm();
        await loadServiceRecords();
      } catch (_) {
      } finally {
        loading.value = false;
      }
    }

    async function deleteService(id) {
      if (!confirm('Hapus catatan servis ini?')) return;
      loading.value = true;
      try {
        await api(`/service-records/${id}`, { method: 'DELETE' });
        toast('Catatan servis dihapus.', 'success');
        if (serviceForm.id === id) resetServiceForm();
        await loadServiceRecords();
      } catch (_) {
      } finally {
        loading.value = false;
      }
    }

    async function loadTxBranchLock(branchId) {
      if (!branchId) return;
      const dash = await api(`/dashboard/branch?branch_id=${branchId}`);
      branchData.value = dash.data;
    }

    function destroyCharts() {
      [barChart, lineChart, donutChart].forEach((c) => c && c.destroy());
      barChart = lineChart = donutChart = null;
    }

    function renderOwnerCharts() {
      destroyCharts();
      const rows = ownerData.value?.agregat_cabang || [];
      const daily = ownerData.value?.arus_kas_harian || [];
      const filtered = !!ownerDashBranchId.value;
      const barEl = document.getElementById('chartBar');
      const lineEl = document.getElementById('chartLine');
      if (!barEl || !lineEl || !window.Chart) return;

      if (filtered && rows.length) {
        const r = rows[0];
        barChart = new Chart(barEl, {
          type: 'bar',
          data: {
            labels: ['Pemasukan', 'Pengeluaran', 'Net'],
            datasets: [{
              label: r.nama_cabang,
              data: [Number(r.pemasukan || 0), Number(r.pengeluaran || 0), Number(r.saldo || 0)],
              backgroundColor: ['#10B981', '#EF4444', '#0F766E'],
              borderRadius: 8,
            }],
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true } },
          },
        });
      } else {
        barChart = new Chart(barEl, {
          type: 'bar',
          data: {
            labels: rows.map((r) => r.nama_cabang),
            datasets: [{
              label: 'Keuntungan',
              data: rows.map((r) => Number(r.saldo || 0)),
              backgroundColor: '#10B981',
              borderRadius: 8,
            }],
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true } },
          },
        });
      }

      if (filtered && daily.length) {
        lineChart = new Chart(lineEl, {
          type: 'line',
          data: {
            labels: daily.map((d) => d.tanggal),
            datasets: [
              {
                label: 'Pemasukan',
                data: daily.map((d) => Number(d.pemasukan || 0)),
                borderColor: '#10B981',
                backgroundColor: 'rgba(16,185,129,.15)',
                tension: .35,
                fill: true,
              },
              {
                label: 'Pengeluaran',
                data: daily.map((d) => Number(d.pengeluaran || 0)),
                borderColor: '#EF4444',
                backgroundColor: 'rgba(239,68,68,.12)',
                tension: .35,
                fill: true,
              },
            ],
          },
          options: { responsive: true, maintainAspectRatio: false },
        });
      } else {
        lineChart = new Chart(lineEl, {
          type: 'line',
          data: {
            labels: rows.map((r) => r.nama_cabang),
            datasets: [
              {
                label: 'Pemasukan',
                data: rows.map((r) => Number(r.pemasukan || 0)),
                borderColor: '#10B981',
                backgroundColor: 'rgba(16,185,129,.15)',
                tension: .35,
                fill: true,
              },
              {
                label: 'Pengeluaran',
                data: rows.map((r) => Number(r.pengeluaran || 0)),
                borderColor: '#EF4444',
                backgroundColor: 'rgba(239,68,68,.12)',
                tension: .35,
                fill: true,
              },
            ],
          },
          options: { responsive: true, maintainAspectRatio: false },
        });
      }
    }

    async function submitTransaction() {
      const amount = parseInputNumber(txForm.amount);
      if (!amount || !txForm.category_id || !txForm.account_id) {
        toast('Lengkapi kategori, akun, dan nominal.', 'error');
        return;
      }
      loading.value = true;
      try {
        const payload = {
          category_id: Number(txForm.category_id),
          account_id: Number(txForm.account_id),
          amount,
          transaction_date: txForm.transaction_date,
          description: txForm.description || null,
        };
        if (isOwner.value) payload.branch_id = Number(txForm.branch_id || user.value.branch_id);
        await api('/transactions', { method: 'POST', body: JSON.stringify(payload) });
        toast('Transaksi berhasil dicatat.', 'success');
        txForm.amount = '';
        txForm.description = '';
        await refreshCurrent();
      } catch (_) {
      } finally {
        loading.value = false;
      }
    }

    async function submitTransferRequest() {
      const amount = parseInputNumber(transferForm.amount);
      if (!amount || !transferForm.to_branch_id || !transferForm.account_id) {
        toast('Lengkapi cabang tujuan, akun, dan nominal.', 'error');
        return;
      }
      loading.value = true;
      try {
        const payload = {
          to_branch_id: Number(transferForm.to_branch_id),
          account_id: Number(transferForm.account_id),
          amount,
        };
        if (transferForm.reason?.trim()) {
          payload.reason = transferForm.reason.trim();
        }
        if (isOwner.value && transferForm.from_branch_id) {
          payload.from_branch_id = Number(transferForm.from_branch_id);
        }
        const res = await api('/transfers/inter-branch/request', {
          method: 'POST',
          body: JSON.stringify(payload),
        });
        toast('Pengajuan transfer dikirim (PENDING).', 'success');
        transferForm.amount = '';
        transferForm.reason = '';
        if (res.data) transfers.value = [res.data, ...transfers.value];
        await refreshCurrent();
      } catch (_) {
      } finally {
        loading.value = false;
      }
    }

    async function submitReconciliation() {
      if (!reconForm.account_id) {
        toast('Pilih akun terlebih dahulu.', 'error');
        return;
      }
      const physical = parseInputNumber(reconForm.physical_balance);
      loading.value = true;
      try {
        const branchId = isOwner.value ? reconForm.branch_id : null;
        if (isOwner.value && !branchId) {
          toast('Pilih cabang terlebih dahulu.', 'error');
          return;
        }
        await loadBranchDashboard(branchId || undefined, reconForm.reconciliation_date);
        await loadAccounts(branchId || user.value?.branch_id);
        const payload = {
          account_id: Number(reconForm.account_id),
          physical_balance: physical,
          reconciliation_date: reconForm.reconciliation_date,
        };
        if (isOwner.value) payload.branch_id = Number(reconForm.branch_id);
        await api('/reconciliations', { method: 'POST', body: JSON.stringify(payload) });
        toast('Rekonsiliasi akun tersimpan.', 'success');
        reconForm.physical_balance = '';
        await refreshCurrent();
      } catch (_) {
      } finally {
        loading.value = false;
      }
    }

    async function onReconBranchChange() {
      reconForm.account_id = '';
      if (reconForm.branch_id) {
        await loadAccounts(reconForm.branch_id);
        await loadBranchDashboard(reconForm.branch_id, reconForm.reconciliation_date);
      }
    }

    async function onReconAccountOrDateChange() {
      const branchId = isOwner.value ? reconForm.branch_id : user.value?.branch_id;
      if (!branchId && isOwner.value) return;
      await loadBranchDashboard(isOwner.value ? reconForm.branch_id : null, reconForm.reconciliation_date);
    }

    async function loadPeriodLocks() {
      const data = await api('/period-locks');
      periodLocks.value = data.data || [];
    }

    async function submitPeriodLock() {
      if (!lockForm.branch_id || !lockForm.period) {
        toast('Pilih cabang dan periode.', 'error');
        return;
      }
      loading.value = true;
      try {
        await api('/period-locks', {
          method: 'POST',
          body: JSON.stringify({
            branch_id: Number(lockForm.branch_id),
            period: lockForm.period,
            is_locked: !!lockForm.is_locked,
          }),
        });
        toast(lockForm.is_locked ? 'Periode dikunci.' : 'Periode dibuka.', 'success');
        await loadPeriodLocks();
        await refreshCurrent();
      } catch (_) {
      } finally {
        loading.value = false;
      }
    }

    async function unlockPeriod(lock) {
      loading.value = true;
      try {
        await api('/period-locks', {
          method: 'POST',
          body: JSON.stringify({
            branch_id: Number(lock.branch_id),
            period: lock.period,
            is_locked: false,
          }),
        });
        toast(`Periode ${lock.period} dibuka.`, 'success');
        await loadPeriodLocks();
      } catch (_) {
      } finally {
        loading.value = false;
      }
    }

    function openReject(id) {
      rejectModal.open = true;
      rejectModal.transferId = id;
      rejectModal.reason = '';
    }

    function openApprove(id) {
      approveModal.open = true;
      approveModal.transferId = id;
      approveModal.password = '';
    }

    async function confirmApprove() {
      if (!approveModal.password) {
        toast('Masukkan kata sandi untuk konfirmasi.', 'error');
        return;
      }
      loading.value = true;
      try {
        await api('/auth/confirm-password', {
          method: 'POST',
          body: JSON.stringify({ password: approveModal.password }),
        });
        await api(`/transfers/inter-branch/${approveModal.transferId}/approve`, { method: 'POST' });
        toast('Transfer disetujui.', 'success');
        approveModal.open = false;
        approveModal.password = '';
        await refreshCurrent();
      } catch (_) {
      } finally {
        loading.value = false;
      }
    }

    function openEditTx(t) {
      editTxModal.open = true;
      editTxModal.id = t.id;
      editTxModal.type = t.category?.type || 'income';
      editTxModal.category_id = t.category_id || t.category?.id || '';
      editTxModal.account_id = t.account_id || t.account?.id || '';
      editTxModal.amount = formatInputNumber(t.amount);
      editTxModal.transaction_date = (t.transaction_date || '').toString().slice(0, 10);
      editTxModal.description = t.description || '';
    }

    async function submitEditTx() {
      const amount = parseInputNumber(editTxModal.amount);
      if (!amount || !editTxModal.category_id || !editTxModal.account_id) {
        toast('Lengkapi kategori, akun, dan nominal.', 'error');
        return;
      }
      loading.value = true;
      try {
        await api(`/transactions/${editTxModal.id}`, {
          method: 'PUT',
          body: JSON.stringify({
            category_id: Number(editTxModal.category_id),
            account_id: Number(editTxModal.account_id),
            amount,
            transaction_date: editTxModal.transaction_date,
            description: editTxModal.description || null,
          }),
        });
        toast('Transaksi berhasil diperbarui.', 'success');
        editTxModal.open = false;
        await refreshCurrent();
      } catch (_) {
      } finally {
        loading.value = false;
      }
    }

    async function deleteTransaction(id) {
      if (!window.confirm('Hapus transaksi ini?')) return;
      loading.value = true;
      try {
        await api(`/transactions/${id}`, { method: 'DELETE' });
        toast('Transaksi berhasil dihapus.', 'success');
        await refreshCurrent();
      } catch (_) {
      } finally {
        loading.value = false;
      }
    }

    async function submitInternalTransfer() {
      const amount = parseInputNumber(internalTransferForm.amount);
      if (!amount || !internalTransferForm.from_account_id || !internalTransferForm.to_account_id) {
        toast('Lengkapi akun asal, akun tujuan, dan nominal.', 'error');
        return;
      }
      if (Number(internalTransferForm.from_account_id) === Number(internalTransferForm.to_account_id)) {
        toast('Akun asal dan tujuan harus berbeda.', 'error');
        return;
      }
      loading.value = true;
      try {
        const payload = {
          from_account_id: Number(internalTransferForm.from_account_id),
          to_account_id: Number(internalTransferForm.to_account_id),
          amount,
          transaction_date: internalTransferForm.transaction_date,
          description: internalTransferForm.description || null,
        };
        if (isOwner.value && internalTransferForm.branch_id) {
          payload.branch_id = Number(internalTransferForm.branch_id);
        }
        await api('/transfers/internal', { method: 'POST', body: JSON.stringify(payload) });
        toast('Transfer antar akun berhasil dicatat.', 'success');
        internalTransferForm.amount = '';
        internalTransferForm.description = '';
        await refreshCurrent();
      } catch (_) {
      } finally {
        loading.value = false;
      }
    }

    async function submitAdjustment() {
      const amount = parseInputNumber(adjustmentForm.amount);
      if (!adjustmentForm.branch_id || !adjustmentForm.account_id || !amount || !adjustmentForm.reason.trim()) {
        toast('Lengkapi cabang, akun, nominal, dan alasan.', 'error');
        return;
      }
      loading.value = true;
      try {
        await api('/adjustments', {
          method: 'POST',
          body: JSON.stringify({
            branch_id: Number(adjustmentForm.branch_id),
            account_id: Number(adjustmentForm.account_id),
            type: adjustmentForm.type,
            amount,
            reason: adjustmentForm.reason.trim(),
            transaction_date: adjustmentForm.transaction_date,
          }),
        });
        toast('Penyesuaian saldo berhasil dicatat.', 'success');
        adjustmentForm.amount = '';
        adjustmentForm.reason = '';
        await refreshCurrent();
      } catch (_) {
      } finally {
        loading.value = false;
      }
    }

    function resetBranchForm() {
      branchForm.id = null;
      branchForm.name = '';
      branchForm.type = activeBranchTypes.value[0]?.code || 'konter';
      branchForm.address = '';
      branchForm.status = 'active';
    }

    function editBranch(b) {
      branchForm.id = b.id;
      branchForm.name = b.name;
      branchForm.type = b.type || activeBranchTypes.value[0]?.code || 'konter';
      branchForm.address = b.address || '';
      branchForm.status = b.status || 'active';
    }

    async function submitBranch() {
      if (!branchForm.name.trim()) {
        toast('Nama cabang wajib diisi.', 'error');
        return;
      }
      if (!branchForm.type) {
        toast('Tipe cabang wajib dipilih.', 'error');
        return;
      }
      loading.value = true;
      try {
        const payload = {
          name: branchForm.name.trim(),
          type: branchForm.type,
          address: branchForm.address || null,
          status: branchForm.status,
        };
        if (branchForm.id) {
          await api(`/branches/${branchForm.id}`, { method: 'PUT', body: JSON.stringify(payload) });
          toast('Cabang berhasil diperbarui.', 'success');
        } else {
          await api('/branches', { method: 'POST', body: JSON.stringify(payload) });
          toast('Cabang berhasil dibuat.', 'success');
        }
        resetBranchForm();
        await refreshCurrent();
      } catch (_) {
      } finally {
        loading.value = false;
      }
    }

    function resetBranchTypeForm() {
      branchTypeForm.id = null;
      branchTypeForm.code = '';
      branchTypeForm.name = '';
      branchTypeForm.allows_service = true;
      branchTypeForm.status = 'active';
    }

    function editBranchType(t) {
      branchTypeForm.id = t.id;
      branchTypeForm.code = t.code || '';
      branchTypeForm.name = t.name || '';
      branchTypeForm.allows_service = !!t.allows_service;
      branchTypeForm.status = t.status || 'active';
    }

    async function submitBranchType() {
      if (!branchTypeForm.name.trim()) {
        toast('Nama tipe wajib diisi.', 'error');
        return;
      }
      if (!branchTypeForm.id && !branchTypeForm.code.trim()) {
        toast('Kode tipe wajib diisi.', 'error');
        return;
      }
      loading.value = true;
      try {
        const payload = {
          name: branchTypeForm.name.trim(),
          allows_service: !!branchTypeForm.allows_service,
          status: branchTypeForm.status,
        };
        if (branchTypeForm.id) {
          await api(`/branch-types/${branchTypeForm.id}`, { method: 'PUT', body: JSON.stringify(payload) });
          toast('Tipe cabang diperbarui.', 'success');
        } else {
          payload.code = branchTypeForm.code.trim().toLowerCase();
          await api('/branch-types', { method: 'POST', body: JSON.stringify(payload) });
          toast('Tipe cabang ditambahkan.', 'success');
        }
        resetBranchTypeForm();
        await loadBranchTypes();
      } catch (_) {
      } finally {
        loading.value = false;
      }
    }

    async function deleteBranchType(t) {
      if (!confirm(`Hapus tipe "${t.name}"?`)) return;
      loading.value = true;
      try {
        await api(`/branch-types/${t.id}`, { method: 'DELETE' });
        toast('Tipe cabang dihapus.', 'success');
        if (branchTypeForm.id === t.id) resetBranchTypeForm();
        await loadBranchTypes();
      } catch (_) {
      } finally {
        loading.value = false;
      }
    }

    function resetAdminForm() {
      adminForm.id = null;
      adminForm.branch_id = '';
      adminForm.name = '';
      adminForm.email = '';
      adminForm.password = '';
    }

    function editAdmin(a) {
      adminForm.id = a.id;
      adminForm.branch_id = a.branch_id;
      adminForm.name = a.name;
      adminForm.email = a.email;
      adminForm.password = '';
    }

    async function submitAdmin() {
      if (!adminForm.branch_id || !adminForm.name.trim() || !adminForm.email.trim()) {
        toast('Lengkapi cabang, nama, dan email.', 'error');
        return;
      }
      if (!adminForm.id && !adminForm.password) {
        toast('Kata sandi wajib diisi untuk admin baru.', 'error');
        return;
      }
      loading.value = true;
      try {
        if (adminForm.id) {
          const payload = {
            branch_id: Number(adminForm.branch_id),
            name: adminForm.name.trim(),
            email: adminForm.email.trim(),
          };
          if (adminForm.password) payload.password = adminForm.password;
          await api(`/admins/${adminForm.id}`, { method: 'PUT', body: JSON.stringify(payload) });
          toast('Admin cabang berhasil diperbarui.', 'success');
        } else {
          await api('/admins', {
            method: 'POST',
            body: JSON.stringify({
              branch_id: Number(adminForm.branch_id),
              name: adminForm.name.trim(),
              email: adminForm.email.trim(),
              password: adminForm.password,
            }),
          });
          toast('Admin cabang berhasil dibuat.', 'success');
        }
        resetAdminForm();
        await refreshCurrent();
      } catch (_) {
      } finally {
        loading.value = false;
      }
    }

    async function deleteAdmin(a) {
      if (!a?.id) return;
      if (!confirm(`Hapus admin "${a.name}" (${a.email})?`)) return;
      loading.value = true;
      try {
        await api(`/admins/${a.id}`, { method: 'DELETE' });
        toast('Admin cabang berhasil dihapus.', 'success');
        if (adminForm.id === a.id) resetAdminForm();
        await refreshCurrent();
      } catch (_) {
      } finally {
        loading.value = false;
      }
    }

    async function submitCategory() {
      if (!categoryForm.name.trim()) {
        toast('Nama kategori wajib diisi.', 'error');
        return;
      }
      loading.value = true;
      try {
        if (categoryForm.id) {
          const payload = {
            name: categoryForm.name.trim(),
            is_active: !!categoryForm.is_active,
          };
          if (isOwner.value) {
            payload.branch_id = categoryForm.branch_id ? Number(categoryForm.branch_id) : null;
          }
          await api(`/categories/${categoryForm.id}`, {
            method: 'PUT',
            body: JSON.stringify(payload),
          });
          toast('Kategori berhasil diperbarui.', 'success');
        } else {
          const payload = {
            name: categoryForm.name.trim(),
            type: categoryForm.type,
          };
          if (isOwner.value) {
            payload.branch_id = categoryForm.branch_id ? Number(categoryForm.branch_id) : null;
          }
          await api('/categories', {
            method: 'POST',
            body: JSON.stringify(payload),
          });
          toast('Kategori berhasil dibuat.', 'success');
        }
        resetCategoryForm();
        await refreshCurrent();
      } catch (_) {
      } finally {
        loading.value = false;
      }
    }

    async function toggleCategoryActive(c) {
      if (!canManageCategory(c)) return;
      loading.value = true;
      try {
        await api(`/categories/${c.id}`, {
          method: 'PUT',
          body: JSON.stringify({
            is_active: !(c.is_active !== false),
          }),
        });
        toast(c.is_active !== false ? 'Kategori dinonaktifkan.' : 'Kategori diaktifkan kembali.', 'success');
        await refreshCurrent();
      } catch (_) {
      } finally {
        loading.value = false;
      }
    }

    async function deleteCategory(c) {
      if (!canManageCategory(c)) return;
      if (!confirm(`Hapus kategori "${c.name}"? Hanya bisa jika belum dipakai di transaksi.`)) return;
      loading.value = true;
      try {
        await api(`/categories/${c.id}`, { method: 'DELETE' });
        toast('Kategori berhasil dihapus.', 'success');
        if (categoryForm.id === c.id) resetCategoryForm();
        await refreshCurrent();
      } catch (_) {
      } finally {
        loading.value = false;
      }
    }

    async function confirmReject() {
      if (!rejectModal.reason.trim()) {
        toast('Alasan penolakan wajib diisi.', 'error');
        return;
      }
      loading.value = true;
      try {
        await api(`/transfers/inter-branch/${rejectModal.transferId}/reject`, {
          method: 'POST',
          body: JSON.stringify({ rejection_reason: rejectModal.reason }),
        });
        toast('Transfer ditolak.', 'success');
        rejectModal.open = false;
        await refreshCurrent();
      } catch (_) {
      } finally {
        loading.value = false;
      }
    }

    async function refreshCurrent() {
      await loadCategories();
      await loadAccounts();
      await ensureBranches();
      if (isOwner.value) {
        if (page.value === 'dashboard' || page.value === 'transfers') {
          await loadOwnerDashboard();
          await loadTransfers();
        }
        if (page.value === 'locks') await loadPeriodLocks();
        if (page.value === 'kelola') {
          await loadAdmins();
          await loadAllAccounts();
          await loadBranchTypes();
          if (accountAssignBranchId.value) {
            await onBranchSetupChange();
          }
        }
        if (page.value === 'employees') await loadEmployees();
        if (page.value === 'transactions' && txForm.branch_id) {
          await loadTxBranchLock(txForm.branch_id);
        }
        if (page.value === 'internal-transfer' && internalTransferForm.branch_id) {
          await loadTxBranchLock(internalTransferForm.branch_id);
        }
      }
      // Admin & Owner: daftar servis + opsi teknisi (sebelumnya hanya Owner → dropdown admin kosong)
      if (page.value === 'services') await loadServiceRecords();
      if (page.value === 'closings') await loadClosingBoard();
      if (page.value === 'attendance') {
        if (attendanceTab.value === 'daily') await loadAttendanceDaily();
        else await loadAttendanceBoard();
      }
      if (page.value === 'payroll') await loadPayrollBoard();
      if (page.value === 'workshop-wages') await loadWorkshopWagePage();
      if (page.value === 'recon') {
        if (isOwner.value && reconForm.branch_id) {
          await loadAccounts(reconForm.branch_id);
          await loadBranchDashboard(reconForm.branch_id, reconForm.reconciliation_date);
        } else if (isAdmin.value) {
          await loadAccounts(user.value?.branch_id);
          await loadBranchDashboard(null, reconForm.reconciliation_date);
        }
      }
      if (isAdmin.value) {
        if (['dashboard', 'transactions', 'internal-transfer', 'adjustments'].includes(page.value)) {
          await loadBranchDashboard();
        }
        if (page.value === 'branch-accounts') {
          await loadAccounts(user.value?.branch_id);
          await loadOpeningBalances(user.value?.branch_id);
          resetAccountForm();
        }
        if (page.value === 'branch-categories') {
          resetCategoryForm();
        }
      }
      if (page.value === 'transactions') await loadTransactions();
      if (page.value === 'reports') {
        if (isAdmin.value && !reportForm.branch_id && user.value?.branch_id) {
          reportForm.branch_id = user.value.branch_id;
        }
      }
    }

    async function go(next) {
      page.value = next;
      syncNavGroups(next);
      if (next === 'profile') fillProfileForm();
      await refreshCurrent();
      if (isOwner.value && next === 'dashboard') {
        await nextTick();
        renderOwnerCharts();
      }
    }

    async function bootstrapApp() {
      bootLoading.value = true;
      try {
        const me = await api('/auth/me');
        user.value = me.data;
        localStorage.setItem(USER_KEY, JSON.stringify(me.data));
        await loadCategories();
        await loadAccounts();
        await ensureBranches();
        page.value = 'dashboard';
        if (isOwner.value) await loadOwnerDashboard();
        else await loadBranchDashboard();
      } catch (_) {
        logout(false);
      } finally {
        bootLoading.value = false;
      }
    }

    watch(
      () => txForm.type,
      () => {
        txForm.category_id = '';
      }
    );

    watch(
      () => txForm.branch_id,
      async (branchId) => {
        if (isOwner.value && page.value === 'transactions' && branchId) {
          await loadAccounts(branchId);
          await loadTxBranchLock(branchId);
          await loadTransactions();
        }
      }
    );

    watch(
      () => internalTransferForm.branch_id,
      async (branchId) => {
        if (isOwner.value && page.value === 'internal-transfer' && branchId) {
          await loadAccounts(branchId);
          await loadTxBranchLock(branchId);
        }
      }
    );

    watch(
      () => adjustmentForm.branch_id,
      async (branchId) => {
        if (isOwner.value && page.value === 'adjustments' && branchId) {
          await loadAccounts(branchId);
        }
      }
    );

    onMounted(async () => {
      if (token.value) await bootstrapApp();
      else await loadDemoAccounts();
    });

    onBeforeUnmount(() => destroyCharts());

    return {
      token,
      user,
      page,
      loading,
      dashLoading,
      bootLoading,
      toasts,
      showPassword,
      loginForm,
      loginError,
      demoAccounts,
      demoPasswordHint,
      useDemoAccount,
      categories,
      accounts,
      allAccounts,
      activeAllAccounts,
      accountForm,
      accountAssignTypeId,
      accountAssignTypeIds,
      accountAssignBranchId,
      accountAssignBranchMode,
      accountAssignBranchIds,
      accountAssignTypePreviewIds,
      openingForm,
      openingBalances,
      branches,
      branchTypes,
      activeBranchTypes,
      ownerData,
      ownerDashBranchId,
      ownerDashMonth,
      ownerDashYear,
      ownerDashMonths,
      ownerDashYears,
      ownerDashScopeLabel,
      ownerDashPeriodLabel,
      ownerCategoryBranchId,
      ownerCategoryScopeLabel,
      branchData,
      transfers,
      transactions,
      periodLocks,
      reportTypes,
      reportForm,
      reportResult,
      txForm,
      txFilter,
      txMeta,
      transferForm,
      internalTransferForm,
      adjustmentForm,
      branchForm,
      branchTypeForm,
      adminForm,
      categoryForm,
      admins,
      employees,
      kelolaTab,
      employeePositionOptions,
      employeeForm,
      employeeFilter,
      toggleEmployeePosition,
      formatEmployeePositions,
      employeesByBranch,
      serviceRecords,
      serviceSummary,
      serviceTechnicians,
      serviceForm,
      serviceFilter,
      editTxModal,
      reconForm,
      lockForm,
      rejectModal,
      approveModal,
      closingConfirm,
      resolveClosingConfirm,
      isOwner,
      isAdmin,
      roleLabel,
      userInitials,
      filteredCategories,
      filterCategories,
      reportFilterCategories,
      expenseCategories,
      incomeCategories,
      editTxCategories,
      destinationBranches,
      ownerMetrics,
      ownerServiceMetrics,
      ownerDashShowsService,
      ownerDashShowsClosing,
      ownerDashShowsWorkshop,
      formatPct,
      pctClass,
      reconDifference,
      reconSystemBalance,
      periodLocked,
      isWorkshopBranch,
      canInputService,
      serviceProfitPreview,
      branchTypeLabel,
      onOwnerDashFilterChange,
      onOwnerDashBranchChange,
      onOwnerCategoryBranchChange,
      navGroups,
      toggleNavGroup,
      formatRp,
      formatDate,
      formatDateTime,
      formatInputNumber,
      parseInputNumber,
      initialsOf,
      rowNo,
      doLogin,
      doLogout,
      profileForm,
      fillProfileForm,
      submitProfile,
      go,
      submitTransaction,
      applyTxFilters,
      resetTxFilters,
      onTxSearchInput,
      onTxFilterTypeChange,
      loadTransactions,
      selectReportType,
      loadReport,
      exportReportPdf,
      submitTransferRequest,
      submitReconciliation,
      onReconBranchChange,
      onReconAccountOrDateChange,
      submitPeriodLock,
      unlockPeriod,
      openReject,
      openApprove,
      confirmApprove,
      confirmReject,
      openEditTx,
      submitEditTx,
      deleteTransaction,
      submitInternalTransfer,
      submitAdjustment,
      resetBranchForm,
      editBranch,
      submitBranch,
      resetBranchTypeForm,
      editBranchType,
      submitBranchType,
      deleteBranchType,
      loadAllAccounts,
      resetAccountForm,
      editAccount,
      onAccountNameInput,
      submitAccount,
      deleteAccount,
      toggleAccountAssignType,
      toggleAccountAssignBranch,
      selectAccountAssignType,
      saveAccountAssignType,
      selectAccountAssignBranch,
      saveAccountAssignBranch,
      onBranchSetupChange,
      isBranchAccountSelected,
      toggleBranchSetupAccount,
      saveBranchSetup,
      branchSetupOpenings,
      ensureBranchSetupOpening,
      resetAdminForm,
      editAdmin,
      submitAdmin,
      deleteAdmin,
      loadEmployees,
      closingBoard,
      closingFilter,
      closingYears,
      closingDays,
      canAccessClosings,
      canAccessAttendance,
      canAccessPayroll,
      canAccessKonterMenu,
      canAccessWorkshopWages,
      workshopBranches,
      konterBranches,
      loadClosingBoard,
      onClosingFilterChange,
      saveClosingDaily,
      saveClosingTarget,
      onClosingFocus,
      onClosingKeydown,
      closingPctClass,
      attendanceTab,
      attendanceDailyDate,
      attendanceDailyRows,
      attendanceDailyMeta,
      attendanceBoard,
      attendanceFilter,
      attendanceYears,
      attendanceDays,
      attendanceDailyCounts,
      attendanceStatusOptions,
      attendanceShort,
      attendanceCellClass,
      onAttendanceBoardCellChange,
      loadAttendanceDaily,
      loadAttendanceBoard,
      onAttendanceFilterChange,
      switchAttendanceTab,
      markAllAttendancePresent,
      copyYesterdayAttendance,
      saveAttendanceDaily,
      payrollBoard,
      payrollFilter,
      payrollYears,
      payrollDetail,
      payrollMonthLabel,
      loadPayrollBoard,
      onPayrollFilterChange,
      savePayrollBoard,
      lockPayrollBoard,
      unlockPayrollBoard,
      openPayrollDetail,
      closePayrollDetail,
      onPayrollManualInput,
      onPayrollFocus,
      onPayrollKeydown,
      wwTab,
      wwDailyDate,
      wwJobs,
      wwTechnicians,
      wwJobTypes,
      wwWeeks,
      wwWeekDetail,
      wwMeta,
      wwFilter,
      wwYears,
      wwSettingsRows,
      wwSettingsMeta,
      wwJobForm,
      loadWorkshopWagePage,
      switchWwTab,
      onWwFilterChange,
      loadWwDailyJobs,
      resetWwJobForm,
      editWwJob,
      submitWwJob,
      deleteWwJob,
      loadWwWeeksAndDetail,
      onWwWeekSelect,
      saveWwSettings,
      payWwWeek,
      reopenWwWeek,
      resetEmployeeForm,
      editEmployee,
      submitEmployee,
      deleteEmployee,
      loadServiceRecords,
      resetServiceForm,
      editService,
      submitService,
      deleteService,
      submitCategory,
      resetCategoryForm,
      editCategory,
      toggleCategoryActive,
      deleteCategory,
      canManageCategory,
      isSystemCategory,
      categoryScopeLabel,
      loadOpeningBalances,
      editOpeningForAccount,
      submitOpeningBalance,
      openingAmountFor,
      openingDateFor,
      onOwnerOpeningBranchChange,
      onAmountInput(e, target, field = 'amount') {
        target[field] = formatInputNumber(e.target.value);
      },
      onPhysicalInput(e) {
        reconForm.physical_balance = formatInputNumber(e.target.value);
      },
    };
  },

  template: `
  <div>
    <div class="toast-wrap">
      <div v-for="t in toasts" :key="t.id" class="toast" :class="'toast-' + t.type">{{ t.message }}</div>
    </div>

    <!-- LOGIN -->
    <div v-if="!token" class="auth-shell">
      <div class="auth-card">
        <header class="auth-brand">
          <h1 class="brand auth-logo">BMS</h1>
          <p class="auth-name">Belawa Management System</p>
          <p class="auth-lead">Satu tempat untuk kelola cabang, keuangan, dan operasional unit usaha.</p>
        </header>

        <div v-if="loginError" class="auth-alert" role="alert">{{ loginError }}</div>

        <form class="auth-form" @submit.prevent="doLogin">
          <p class="auth-form-title">Masuk ke akun</p>
          <div class="field">
            <label for="login-email">Email</label>
            <input id="login-email" v-model="loginForm.email" type="email" autocomplete="username" placeholder="nama@email.com" required />
          </div>
          <div class="field">
            <label for="login-password">Kata sandi</label>
            <div class="password-wrap">
              <input id="login-password" :type="showPassword ? 'text' : 'password'" v-model="loginForm.password" autocomplete="current-password" placeholder="Masukkan kata sandi" required />
              <button type="button" class="eye-btn" @click="showPassword = !showPassword" :aria-label="showPassword ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi'">
                <svg v-if="!showPassword" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
                <svg v-else width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 3l18 18"/><path d="M10.6 10.6a2 2 0 102.8 2.8"/><path d="M9.9 5.1A10.6 10.6 0 0112 5c6 0 10 7 10 7a17.7 17.7 0 01-3.1 3.9"/><path d="M6.1 6.1C3.9 7.7 2 12 2 12a17.3 17.3 0 006.2 5.6"/></svg>
              </button>
            </div>
          </div>
          <button class="btn btn-primary auth-submit" :disabled="loading">{{ loading ? 'Memproses…' : 'Masuk' }}</button>
        </form>

        <div v-if="demoAccounts.length" class="auth-demo">
          <div class="auth-demo-head">
            <strong>Akun uji</strong>
            <span>Sementara · klik untuk mengisi · sandi: <code>{{ demoPasswordHint }}</code></span>
          </div>
          <div class="auth-demo-list">
            <button
              v-for="a in demoAccounts"
              :key="a.email"
              type="button"
              class="demo-account-btn"
              @click="useDemoAccount(a)"
            >
              <span class="demo-top">
                <span class="demo-role">{{ a.role_label }}</span>
                <span v-if="a.branch" class="demo-branch">{{ a.branch }}</span>
              </span>
              <span class="demo-name">{{ a.name }}</span>
              <span class="demo-email">{{ a.email }}</span>
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- APP -->
    <div v-else class="app-shell">
      <aside class="sidebar">
        <div class="logo brand">BMS</div>
        <div class="logo-sub">Belawa Management System</div>

        <div class="nav-group">
          <button type="button" class="nav-group-toggle" :class="{open: navGroups.utama}" @click="toggleNavGroup('utama')">
            <span>Utama</span>
            <span class="chev"></span>
          </button>
          <div v-show="navGroups.utama" class="nav-group-items">
            <button class="nav-btn" :class="{active: page==='dashboard'}" @click="go('dashboard')">
              <svg class="nav-ico" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/></svg>
              <span>Dasbor</span>
            </button>
            <button class="nav-btn" :class="{active: page==='transactions'}" @click="go('transactions')">
              <svg class="nav-ico" viewBox="0 0 24 24"><path d="M12 3v18"/><path d="M7 8h7a3 3 0 010 6H9a3 3 0 000 6h8"/></svg>
              <span>Transaksi</span>
            </button>
            <button v-if="isAdmin || isOwner" class="nav-btn" :class="{active: page==='recon'}" @click="go('recon')">
              <svg class="nav-ico" viewBox="0 0 24 24"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg>
              <span>Rekonsiliasi</span>
            </button>
            <button v-if="isAdmin" class="nav-btn" :class="{active: page==='branch-accounts'}" @click="go('branch-accounts')">
              <svg class="nav-ico" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M7 10h4"/><path d="M7 14h10"/><path d="M15 8v4"/></svg>
              <span>Akun Cabang</span>
            </button>
            <button v-if="isAdmin" class="nav-btn" :class="{active: page==='branch-categories'}" @click="go('branch-categories')">
              <svg class="nav-ico" viewBox="0 0 24 24"><path d="M4 6h16"/><path d="M4 12h10"/><path d="M4 18h14"/><path d="M18 10v8"/><path d="M15 14h6"/></svg>
              <span>Kategori</span>
            </button>
          </div>
        </div>

        <div class="nav-group">
          <button type="button" class="nav-group-toggle" :class="{open: navGroups.transfer}" @click="toggleNavGroup('transfer')">
            <span>Transfer</span>
            <span class="chev"></span>
          </button>
          <div v-show="navGroups.transfer" class="nav-group-items">
            <button class="nav-btn" :class="{active: page==='internal-transfer'}" @click="go('internal-transfer')">
              <svg class="nav-ico" viewBox="0 0 24 24"><path d="M4 12h16"/><path d="M12 4v16"/><circle cx="12" cy="12" r="9"/></svg>
              <span>Antar Akun</span>
            </button>
            <button class="nav-btn" :class="{active: page==='transfers'}" @click="go('transfers')">
              <svg class="nav-ico" viewBox="0 0 24 24"><path d="M7 7h11l-3-3"/><path d="M17 17H6l3 3"/><path d="M18 7v4"/><path d="M6 13v4"/></svg>
              <span>Antar Cabang</span>
            </button>
          </div>
        </div>

        <div v-if="canAccessKonterMenu" class="nav-group">
          <button type="button" class="nav-group-toggle" :class="{open: navGroups.konter}" @click="toggleNavGroup('konter')">
            <span>Konter</span>
            <span class="chev"></span>
          </button>
          <div v-show="navGroups.konter" class="nav-group-items">
            <button v-if="canAccessClosings" class="nav-btn" :class="{active: page==='services'}" @click="go('services')">
              <svg class="nav-ico" viewBox="0 0 24 24"><path d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.76 3.76z"/></svg>
              <span>Catatan Servis</span>
            </button>
            <button v-if="canAccessClosings" class="nav-btn" :class="{active: page==='closings'}" @click="go('closings')">
              <svg class="nav-ico" viewBox="0 0 24 24"><path d="M4 19V5"/><path d="M4 19h16"/><path d="M8 15v4"/><path d="M12 11v8"/><path d="M16 7v12"/></svg>
              <span>Target Closingan</span>
            </button>
            <button v-if="canAccessPayroll" class="nav-btn" :class="{active: page==='payroll'}" @click="go('payroll')">
              <svg class="nav-ico" viewBox="0 0 24 24"><path d="M12 1v22"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
              <span>Gaji Konter</span>
            </button>
          </div>
        </div>

        <div v-if="canAccessWorkshopWages" class="nav-group">
          <button type="button" class="nav-group-toggle" :class="{open: navGroups.bengkel}" @click="toggleNavGroup('bengkel')">
            <span>Bengkel</span>
            <span class="chev"></span>
          </button>
          <div v-show="navGroups.bengkel" class="nav-group-items">
            <button class="nav-btn" :class="{active: page==='workshop-wages'}" @click="go('workshop-wages')">
              <svg class="nav-ico" viewBox="0 0 24 24"><path d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.76 3.76z"/></svg>
              <span>Upah Kerja</span>
            </button>
          </div>
        </div>

        <div v-if="isOwner || canAccessAttendance" class="nav-group">
          <button type="button" class="nav-group-toggle" :class="{open: navGroups.karyawan}" @click="toggleNavGroup('karyawan')">
            <span>Karyawan</span>
            <span class="chev"></span>
          </button>
          <div v-show="navGroups.karyawan" class="nav-group-items">
            <button v-if="canAccessAttendance" class="nav-btn" :class="{active: page==='attendance'}" @click="go('attendance')">
              <svg class="nav-ico" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/><path d="M8 14h.01"/><path d="M12 14h.01"/><path d="M16 14h.01"/><path d="M8 18h.01"/><path d="M12 18h.01"/></svg>
              <span>Absensi</span>
            </button>
            <button v-if="isOwner" class="nav-btn" :class="{active: page==='employees'}" @click="go('employees')">
              <svg class="nav-ico" viewBox="0 0 24 24"><circle cx="9" cy="8" r="3"/><circle cx="17" cy="9" r="2.5"/><path d="M3 19a6 6 0 0112 0"/><path d="M14 19a4.5 4.5 0 017 0"/></svg>
              <span>Data Karyawan</span>
            </button>
          </div>
        </div>

        <div class="nav-group">
          <button type="button" class="nav-group-toggle" :class="{open: navGroups.laporan}" @click="toggleNavGroup('laporan')">
            <span>Laporan</span>
            <span class="chev"></span>
          </button>
          <div v-show="navGroups.laporan" class="nav-group-items">
            <button class="nav-btn" :class="{active: page==='reports'}" @click="go('reports')">
              <svg class="nav-ico" viewBox="0 0 24 24"><path d="M4 19V5"/><path d="M4 19h16"/><path d="M8 15v4"/><path d="M12 11v8"/><path d="M16 7v12"/></svg>
              <span>Buat Laporan</span>
            </button>
          </div>
        </div>

        <div v-if="isOwner" class="nav-group">
          <button type="button" class="nav-group-toggle" :class="{open: navGroups.sistem}" @click="toggleNavGroup('sistem')">
            <span>Sistem</span>
            <span class="chev"></span>
          </button>
          <div v-show="navGroups.sistem" class="nav-group-items">
            <button class="nav-btn" :class="{active: page==='kelola'}" @click="go('kelola')">
              <svg class="nav-ico" viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.5"/><path d="M5 20a7 7 0 0114 0"/><path d="M19 4v4"/><path d="M17 6h4"/></svg>
              <span>Kelola</span>
            </button>
            <button class="nav-btn" :class="{active: page==='adjustments'}" @click="go('adjustments')">
              <svg class="nav-ico" viewBox="0 0 24 24"><path d="M12 3v18"/><path d="M5 12h14"/><path d="M7 7l10 10"/><path d="M17 7L7 17"/></svg>
              <span>Penyesuaian</span>
            </button>
            <button class="nav-btn" :class="{active: page==='locks'}" @click="go('locks')">
              <svg class="nav-ico" viewBox="0 0 24 24"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 018 0v3"/></svg>
              <span>Kunci Periode</span>
            </button>
          </div>
        </div>
      </aside>

      <main class="main">
        <header class="topbar">
          <div class="topbar-user">
            <div class="avatar" aria-hidden="true">{{ userInitials }}</div>
            <div class="topbar-meta">
              <strong>{{ user?.name }}</strong>
              <span class="role-chip">{{ roleLabel }}</span>
            </div>
          </div>
          <div class="topbar-actions">
            <button class="btn btn-ghost btn-sm" type="button" :class="{active: page==='profile'}" @click="go('profile')">Akun</button>
            <button class="btn btn-ghost btn-sm" type="button" @click="doLogout">Keluar</button>
          </div>
        </header>

        <div class="main-scroll">
        <section v-if="page==='profile'" class="card" style="max-width:560px">
          <div class="page-head" style="margin-bottom:12px;padding:0">
            <div>
              <h2 class="brand">Akun Saya</h2>
              <p>Ubah nama, email, atau kata sandi. Wajib isi kata sandi saat ini.</p>
            </div>
          </div>
          <div class="form-grid">
            <div class="field">
              <label>Nama</label>
              <input v-model="profileForm.name" autocomplete="name" />
            </div>
            <div class="field">
              <label>Email</label>
              <input v-model="profileForm.email" type="email" autocomplete="username" />
            </div>
            <div class="field">
              <label>Kata sandi saat ini <span class="opt">(wajib)</span></label>
              <input v-model="profileForm.current_password" type="password" autocomplete="current-password" />
            </div>
            <div class="field">
              <label>Kata sandi baru <span class="opt">(opsional)</span></label>
              <input v-model="profileForm.password" type="password" autocomplete="new-password" placeholder="Minimal 6 karakter" />
            </div>
            <div class="field">
              <label>Konfirmasi kata sandi baru</label>
              <input v-model="profileForm.password_confirmation" type="password" autocomplete="new-password" />
            </div>
            <div style="display:flex;gap:8px">
              <button class="btn btn-primary" type="button" :disabled="loading" @click="submitProfile">Simpan Perubahan</button>
              <button class="btn btn-ghost" type="button" @click="fillProfileForm">Reset</button>
            </div>
          </div>
        </section>

        <div v-if="bootLoading || (page === 'dashboard' && dashLoading)" class="grid-4" style="margin-bottom:14px">
          <div class="skeleton sk-card"></div>
          <div class="skeleton sk-card"></div>
          <div class="skeleton sk-card"></div>
          <div class="skeleton sk-card"></div>
        </div>

        <!-- OWNER DASHBOARD -->
        <section v-if="page==='dashboard' && isOwner">
          <div class="page-head">
            <div>
              <h2 class="brand">Dasbor Pemilik</h2>
              <p>
                {{ ownerDashPeriodLabel }}
                · {{ ownerDashBranchId ? ('Cabang: ' + ownerDashScopeLabel) : 'Semua cabang' }}
              </p>
            </div>
            <div class="dash-filters">
              <div class="field" style="margin:0;min-width:140px">
                <label>Bulan</label>
                <select v-model.number="ownerDashMonth" @change="onOwnerDashFilterChange">
                  <option v-for="m in ownerDashMonths" :key="m.value" :value="m.value">{{ m.label }}</option>
                </select>
              </div>
              <div class="field" style="margin:0;min-width:100px">
                <label>Tahun</label>
                <select v-model.number="ownerDashYear" @change="onOwnerDashFilterChange">
                  <option v-for="y in ownerDashYears" :key="y" :value="y">{{ y }}</option>
                </select>
              </div>
              <div class="field" style="margin:0;min-width:200px">
                <label>Cabang</label>
                <select v-model="ownerDashBranchId" @change="onOwnerDashFilterChange">
                  <option value="">Semua cabang</option>
                  <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                </select>
              </div>
            </div>
          </div>

          <div class="grid-4">
            <div class="card metric">
              <div class="label">Total Omzet</div>
              <div class="value value-income">{{ formatRp(ownerMetrics.omzet) }}</div>
              <div class="metric-sub" :class="pctClass(ownerMetrics.change?.omzet_pct)">
                vs bln lalu {{ formatPct(ownerMetrics.change?.omzet_pct) }}
              </div>
            </div>
            <div class="card metric">
              <div class="label">Total Pengeluaran</div>
              <div class="value value-expense">{{ formatRp(ownerMetrics.beban) }}</div>
              <div class="metric-sub" :class="pctClass(ownerMetrics.change?.beban_pct)">
                vs bln lalu {{ formatPct(ownerMetrics.change?.beban_pct) }}
              </div>
            </div>
            <div class="card metric">
              <div class="label">Net Profit</div>
              <div class="value" :class="ownerMetrics.profit >= 0 ? 'value-income' : 'value-expense'">{{ formatRp(ownerMetrics.profit) }}</div>
              <div class="metric-sub" :class="pctClass(ownerMetrics.change?.profit_pct)">
                vs bln lalu {{ formatPct(ownerMetrics.change?.profit_pct) }}
              </div>
            </div>
            <div class="card metric">
              <div class="label">{{ ownerDashBranchId ? 'Saldo Cabang' : 'Saldo Konsolidasi' }}</div>
              <div class="value">{{ formatRp(ownerMetrics.saldo) }}</div>
              <div class="metric-sub">posisi akhir periode</div>
            </div>
          </div>

          <div class="grid-4" style="margin-top:14px">
            <div class="card metric">
              <div class="label">Gaji Konter</div>
              <div class="value">{{ formatRp(ownerData?.payroll?.total) }}</div>
              <div class="metric-sub">
                {{ ownerData?.payroll?.karyawan || 0 }} karyawan ·
                locked {{ ownerData?.payroll?.locked || 0 }} ·
                draft {{ ownerData?.payroll?.draft || 0 }}
              </div>
            </div>
            <div v-if="ownerDashShowsClosing" class="card metric">
              <div class="label">Closing vs Target</div>
              <div class="value" style="font-size:1.35rem">
                {{ ownerData?.closing?.qty ?? 0 }}
                <span style="font-size:.85rem;color:#64748B;font-weight:500">/ {{ ownerData?.closing?.target ?? 0 }}</span>
              </div>
              <div class="metric-sub" :class="(ownerData?.closing?.pct ?? 0) >= 100 ? 'value-income' : ''">
                {{ ownerData?.closing?.pct != null ? (ownerData.closing.pct + '% dari target') : 'Belum ada target' }}
              </div>
            </div>
            <div class="card metric">
              <div class="label">Absensi Hari Ini</div>
              <div class="value" style="font-size:1.2rem">
                H {{ ownerData?.attendance_today?.present ?? 0 }}
                · I {{ ownerData?.attendance_today?.leave ?? 0 }}
                · S {{ ownerData?.attendance_today?.sick ?? 0 }}
                · A {{ ownerData?.attendance_today?.absent ?? 0 }}
              </div>
              <div class="metric-sub">
                belum absen {{ ownerData?.attendance_today?.unmarked ?? 0 }}
                / {{ ownerData?.attendance_today?.total_employees ?? 0 }}
              </div>
            </div>
            <div v-if="ownerData?.workshop_week" class="card metric">
              <div class="label">Upah Bengkel Minggu Ini</div>
              <div class="value">{{ formatRp(ownerData.workshop_week.gross) }}</div>
              <div class="metric-sub">
                {{ ownerData.workshop_week.job_count || 0 }} job ·
                teknisi {{ formatRp(ownerData.workshop_week.tech_net) }} ·
                {{ (ownerData.workshop_week.status || '').toUpperCase() }}
              </div>
            </div>
          </div>

          <div v-if="ownerDashShowsService" class="grid-2" style="margin-top:14px">
            <div class="card metric">
              <div class="label">Penghasilan Service</div>
              <div class="value value-income">{{ formatRp(ownerServiceMetrics.total_harga) }}</div>
              <div class="metric-sub">
                {{ ownerServiceMetrics.jumlah || 0 }} job · profit {{ formatRp(ownerServiceMetrics.total_profit) }}
                · vs bln lalu
                <span :class="pctClass(ownerData?.service_change?.harga_pct)">{{ formatPct(ownerData?.service_change?.harga_pct) }}</span>
              </div>
            </div>
            <div class="card">
              <div class="panel-title">Service per Cabang</div>
              <div class="table-wrap">
                <table>
                  <thead><tr><th class="col-no">No</th><th>Cabang</th><th>Job</th><th>Omzet</th><th>Profit</th></tr></thead>
                  <tbody>
                    <tr v-for="(s, idx) in (ownerServiceMetrics.per_cabang || [])" :key="s.branch_id">
                      <td class="col-no">{{ rowNo(idx) }}</td>
                      <td>{{ s.nama_cabang }}</td>
                      <td>{{ s.jumlah }}</td>
                      <td class="value-income">{{ formatRp(s.total_harga) }}</td>
                      <td :class="Number(s.total_profit) >= 0 ? 'value-income' : 'value-expense'">{{ formatRp(s.total_profit) }}</td>
                    </tr>
                    <tr v-if="!(ownerServiceMetrics.per_cabang || []).length">
                      <td colspan="5">Belum ada data service periode ini.</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <div class="grid-2" style="margin-top:14px">
            <div class="card">
              <div class="panel-title">{{ ownerDashBranchId ? 'Ringkasan Cabang' : 'Komparasi Cabang' }}</div>
              <div class="chart-box"><canvas id="chartBar"></canvas></div>
            </div>
            <div class="card">
              <div class="panel-title">{{ ownerDashBranchId ? 'Arus Kas Harian' : 'Tren Arus Kas' }}</div>
              <div class="chart-box"><canvas id="chartLine"></canvas></div>
            </div>
          </div>

          <div class="card" style="margin-top:14px">
            <div class="panel-title">Top 5 Kategori</div>
            <div class="filter-meta" style="margin-bottom:12px">{{ ownerDashScopeLabel }} · {{ ownerDashPeriodLabel }} · detail lengkap di Laporan</div>
            <div class="grid-2">
              <div>
                <div class="panel-title">Pendapatan</div>
                <div class="table-wrap">
                  <table>
                    <thead>
                      <tr><th class="col-no">No</th><th>Kategori</th><th>Jumlah</th><th>Total</th></tr>
                    </thead>
                    <tbody>
                      <tr v-for="(r, idx) in (ownerData?.saldo_per_kategori_top?.pemasukan || [])" :key="'in-'+r.category_id">
                        <td class="col-no">{{ rowNo(idx) }}</td>
                        <td>{{ r.nama }}</td>
                        <td>{{ r.jumlah }}</td>
                        <td class="value-income" style="font-weight:600">{{ formatRp(r.total) }}</td>
                      </tr>
                      <tr v-if="!(ownerData?.saldo_per_kategori_top?.pemasukan || []).length">
                        <td colspan="4">Belum ada data pendapatan.</td>
                      </tr>
                    </tbody>
                    <tfoot>
                      <tr>
                        <td></td>
                        <td><strong>Total Pendapatan</strong></td>
                        <td></td>
                        <td class="value-income"><strong>{{ formatRp(ownerData?.saldo_per_kategori_top?.total_pemasukan) }}</strong></td>
                      </tr>
                    </tfoot>
                  </table>
                </div>
              </div>
              <div>
                <div class="panel-title">Pengeluaran</div>
                <div class="table-wrap">
                  <table>
                    <thead>
                      <tr><th class="col-no">No</th><th>Kategori</th><th>Jumlah</th><th>Total</th></tr>
                    </thead>
                    <tbody>
                      <tr v-for="(r, idx) in (ownerData?.saldo_per_kategori_top?.pengeluaran || [])" :key="'ex-'+r.category_id">
                        <td class="col-no">{{ rowNo(idx) }}</td>
                        <td>{{ r.nama }}</td>
                        <td>{{ r.jumlah }}</td>
                        <td class="value-expense" style="font-weight:600">{{ formatRp(r.total) }}</td>
                      </tr>
                      <tr v-if="!(ownerData?.saldo_per_kategori_top?.pengeluaran || []).length">
                        <td colspan="4">Belum ada data pengeluaran.</td>
                      </tr>
                    </tbody>
                    <tfoot>
                      <tr>
                        <td></td>
                        <td><strong>Total Pengeluaran</strong></td>
                        <td></td>
                        <td class="value-expense"><strong>{{ formatRp(ownerData?.saldo_per_kategori_top?.total_pengeluaran) }}</strong></td>
                      </tr>
                    </tfoot>
                  </table>
                </div>
              </div>
            </div>
          </div>

          <div class="grid-2" style="margin-top:14px">
            <div class="card">
              <div class="panel-title">Persetujuan Transfer Cabang</div>
              <div class="table-wrap">
                <table>
                  <thead>
                    <tr><th class="col-no">No</th><th>Dari</th><th>Ke</th><th>Akun</th><th>Nominal</th><th>Pemohon</th><th>Aksi</th></tr>
                  </thead>
                  <tbody>
                    <tr v-for="(t, idx) in (ownerData?.transfer_pending || [])" :key="t.id">
                      <td class="col-no">{{ rowNo(idx) }}</td>
                      <td>{{ t.from_branch?.name || t.fromBranch?.name }}</td>
                      <td>{{ t.to_branch?.name || t.toBranch?.name }}</td>
                      <td>{{ t.account?.name || '—' }}</td>
                      <td>{{ formatRp(t.amount) }}</td>
                      <td>{{ t.requester?.name }}</td>
                      <td>
                        <button class="btn btn-success btn-sm" @click="openApprove(t.id)">Setujui</button>
                        <button class="btn btn-danger btn-sm" @click="openReject(t.id)">Tolak</button>
                      </td>
                    </tr>
                    <tr v-if="!(ownerData?.transfer_pending || []).length">
                      <td colspan="7">Tidak ada transfer pending.</td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <div style="margin-top:16px" class="panel-title">Alert Selisih</div>
              <div v-for="a in (ownerData?.alert_selisih_rekonsiliasi || [])" :key="a.id" style="font-size:.85rem;margin-bottom:8px">
                <strong>{{ a.branch?.name }}</strong>
                <span v-if="a.account"> · {{ a.account.name }}</span>
                — selisih {{ formatRp(a.difference) }}
              </div>
              <div v-if="!(ownerData?.alert_selisih_rekonsiliasi || []).length" style="color:#64748B;font-size:.85rem">Tidak ada alert.</div>
            </div>
            <div class="card">
              <div class="panel-title">{{ ownerDashBranchId ? 'Saldo per Akun' : 'Saldo per Cabang' }}</div>
              <div class="table-wrap" v-if="ownerDashBranchId">
                <table>
                  <thead>
                    <tr><th class="col-no">No</th><th>Akun</th><th>Saldo</th></tr>
                  </thead>
                  <tbody>
                    <tr v-for="(a, idx) in (ownerData?.saldo_per_akun || [])" :key="a.account_id">
                      <td class="col-no">{{ rowNo(idx) }}</td>
                      <td>{{ a.nama_akun }}</td>
                      <td :style="{color: Number(a.saldo) >= 0 ? '#10B981' : '#EF4444', fontWeight: 600}">
                        {{ formatRp(a.saldo) }}
                      </td>
                    </tr>
                    <tr v-if="!(ownerData?.saldo_per_akun || []).length">
                      <td colspan="3">Belum ada data akun.</td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <div class="table-wrap" v-else>
                <table>
                  <thead>
                    <tr><th class="col-no">No</th><th>Cabang</th><th>Saldo Kas</th></tr>
                  </thead>
                  <tbody>
                    <tr v-for="(r, idx) in (ownerData?.saldo_per_cabang || [])" :key="r.branch_id">
                      <td class="col-no">{{ rowNo(idx) }}</td>
                      <td>{{ r.nama_cabang }}</td>
                      <td :style="{color: Number(r.saldo) >= 0 ? '#10B981' : '#EF4444', fontWeight: 600}">
                        {{ formatRp(r.saldo) }}
                      </td>
                    </tr>
                    <tr v-if="!(ownerData?.saldo_per_cabang || []).length">
                      <td colspan="3">Belum ada data cabang.</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </section>

        <!-- BRANCH DASHBOARD -->
        <section v-if="page==='dashboard' && isAdmin">
          <div class="page-head">
            <div>
              <h2 class="brand">Dasbor {{ user?.branch?.name }}</h2>
              <p>Operasional kas harian cabang</p>
            </div>
          </div>

          <div v-if="periodLocked" class="banner-lock">
            Periode Pembukuan Bulan Ini Telah Dikunci Oleh Owner. Anda Tidak Dapat Menambah atau Mengubah Data.
          </div>

          <div class="grid-4">
            <div class="card metric">
              <div class="label">Saldo Total</div>
              <div class="value">{{ formatRp(branchData?.saldo_kas) }}</div>
            </div>
            <div class="card metric">
              <div class="label">Omzet Bulan Ini</div>
              <div class="value value-income">{{ formatRp(branchData?.periode?.omzet) }}</div>
            </div>
            <div class="card metric">
              <div class="label">Pengeluaran Bulan Ini</div>
              <div class="value value-expense">{{ formatRp(branchData?.periode?.beban) }}</div>
            </div>
            <div class="card metric">
              <div class="label">Rekonsiliasi</div>
              <div class="value" style="font-size:1.1rem">
                {{ branchData?.recon_status?.checked_today ?? 0 }}
                <span style="font-size:.85rem;color:#64748B;font-weight:500">/ {{ branchData?.recon_status?.total_accounts ?? 0 }} akun hari ini</span>
              </div>
              <div class="metric-sub" :class="(branchData?.recon_status?.stale_accounts || 0) > 0 ? 'value-expense' : 'value-income'">
                {{ (branchData?.recon_status?.stale_accounts || 0) > 0
                  ? (branchData.recon_status.stale_accounts + ' akun belum dicek ≥2 hari')
                  : (periodLocked ? 'Periode terkunci' : 'Semua akun terpantau') }}
              </div>
            </div>
          </div>

          <div class="grid-4" style="margin-top:14px">
            <div class="card metric">
              <div class="label">Absensi Hari Ini</div>
              <div class="value" style="font-size:1.15rem">
                H {{ branchData?.attendance_today?.present ?? 0 }}
                · I {{ branchData?.attendance_today?.leave ?? 0 }}
                · S {{ branchData?.attendance_today?.sick ?? 0 }}
                · A {{ branchData?.attendance_today?.absent ?? 0 }}
              </div>
              <div class="metric-sub">belum absen {{ branchData?.attendance_today?.unmarked ?? 0 }}</div>
            </div>
            <template v-if="!isWorkshopBranch">
              <div class="card metric">
                <div class="label">Penghasilan Service</div>
                <div class="value value-income">{{ formatRp(branchData?.service?.total_harga) }}</div>
                <div class="metric-sub">
                  {{ branchData?.service?.jumlah || 0 }} job · profit {{ formatRp(branchData?.service?.total_profit) }}
                </div>
              </div>
              <div class="card metric">
                <div class="label">Closing vs Target</div>
                <div class="value" style="font-size:1.25rem">
                  {{ branchData?.closing?.qty ?? 0 }}
                  <span style="font-size:.85rem;color:#64748B;font-weight:500">/ {{ branchData?.closing?.target ?? 0 }}</span>
                </div>
                <div class="metric-sub">
                  {{ branchData?.closing?.pct != null ? (branchData.closing.pct + '% target bulan ini') : 'Belum ada target' }}
                </div>
              </div>
              <div class="card metric">
                <div class="label">Net Profit Bulan Ini</div>
                <div class="value" :class="Number(branchData?.periode?.profit || 0) >= 0 ? 'value-income' : 'value-expense'">
                  {{ formatRp(branchData?.periode?.profit) }}
                </div>
                <div class="metric-sub">dari transaksi kas</div>
              </div>
            </template>
            <template v-else>
              <div class="card metric">
                <div class="label">Omzet Kerja Minggu Ini</div>
                <div class="value value-income">{{ formatRp(branchData?.workshop_week?.gross) }}</div>
                <div class="metric-sub">{{ branchData?.workshop_week?.job_count || 0 }} job · {{ branchData?.workshop_week?.label || '' }}</div>
              </div>
              <div class="card metric">
                <div class="label">Upah Teknisi</div>
                <div class="value">{{ formatRp(branchData?.workshop_week?.tech_net) }}</div>
                <div class="metric-sub">bagian toko {{ formatRp(branchData?.workshop_week?.shop_share) }}</div>
              </div>
              <div class="card metric">
                <div class="label">Status Minggu</div>
                <div class="value" style="font-size:1.2rem" :class="branchData?.workshop_week?.status === 'paid' ? 'value-income' : ''">
                  {{ (branchData?.workshop_week?.status || 'open').toUpperCase() }}
                </div>
                <div class="metric-sub">{{ periodLocked ? 'Periode terkunci' : 'Periode terbuka' }}</div>
              </div>
            </template>
          </div>

          <div class="card" style="margin-top:14px">
            <div class="panel-title">Saldo per Akun</div>
            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th class="col-no">No</th>
                    <th>Akun</th>
                    <th>Saldo</th>
                    <th>Saldo Awal</th>
                    <th>Terakhir Dicek</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(a, idx) in (branchData?.saldo_per_akun || [])" :key="a.account_id">
                    <td class="col-no">{{ rowNo(idx) }}</td>
                    <td>{{ a.nama_akun }}</td>
                    <td :style="{color: Number(a.saldo) >= 0 ? '#10B981' : '#EF4444', fontWeight: 600}">{{ formatRp(a.saldo) }}</td>
                    <td style="font-size:.85rem;color:#64748B">
                      <template v-if="a.saldo_awal != null">
                        {{ formatRp(a.saldo_awal) }}
                        <div style="font-size:.75rem">sejak {{ formatDate(a.tanggal_awal) }}</div>
                      </template>
                      <span v-else>—</span>
                    </td>
                    <td style="font-size:.85rem">
                      <template v-if="a.terakhir_dicek">
                        {{ formatDate(a.terakhir_dicek) }}
                        <div :style="{color: Math.abs(Number(a.selisih_terakhir||0)) < 0.01 ? '#10B981' : '#EF4444', fontSize:'.75rem'}">
                          selisih {{ formatRp(a.selisih_terakhir) }}
                        </div>
                      </template>
                      <span v-else style="color:#64748B">Belum pernah</span>
                    </td>
                  </tr>
                  <tr v-if="!(branchData?.saldo_per_akun || []).length">
                    <td colspan="5">Belum ada data akun.</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <div class="card" style="margin-top:14px">
            <div class="panel-title">Top 5 Kategori Bulan Ini</div>
            <div class="grid-2">
              <div>
                <div class="panel-title">Pendapatan</div>
                <div class="table-wrap">
                  <table>
                    <thead>
                      <tr><th class="col-no">No</th><th>Kategori</th><th>Jumlah</th><th>Total</th></tr>
                    </thead>
                    <tbody>
                      <tr v-for="(r, idx) in (branchData?.saldo_per_kategori_top?.pemasukan || [])" :key="'bin-'+r.category_id">
                        <td class="col-no">{{ rowNo(idx) }}</td>
                        <td>{{ r.nama }}</td>
                        <td>{{ r.jumlah }}</td>
                        <td class="value-income" style="font-weight:600">{{ formatRp(r.total) }}</td>
                      </tr>
                      <tr v-if="!(branchData?.saldo_per_kategori_top?.pemasukan || []).length">
                        <td colspan="4">Belum ada data pendapatan.</td>
                      </tr>
                    </tbody>
                    <tfoot>
                      <tr>
                        <td></td>
                        <td><strong>Total Pendapatan</strong></td>
                        <td></td>
                        <td class="value-income"><strong>{{ formatRp(branchData?.saldo_per_kategori_top?.total_pemasukan) }}</strong></td>
                      </tr>
                    </tfoot>
                  </table>
                </div>
              </div>
              <div>
                <div class="panel-title">Pengeluaran</div>
                <div class="table-wrap">
                  <table>
                    <thead>
                      <tr><th class="col-no">No</th><th>Kategori</th><th>Jumlah</th><th>Total</th></tr>
                    </thead>
                    <tbody>
                      <tr v-for="(r, idx) in (branchData?.saldo_per_kategori_top?.pengeluaran || [])" :key="'bex-'+r.category_id">
                        <td class="col-no">{{ rowNo(idx) }}</td>
                        <td>{{ r.nama }}</td>
                        <td>{{ r.jumlah }}</td>
                        <td class="value-expense" style="font-weight:600">{{ formatRp(r.total) }}</td>
                      </tr>
                      <tr v-if="!(branchData?.saldo_per_kategori_top?.pengeluaran || []).length">
                        <td colspan="4">Belum ada data pengeluaran.</td>
                      </tr>
                    </tbody>
                    <tfoot>
                      <tr>
                        <td></td>
                        <td><strong>Total Pengeluaran</strong></td>
                        <td></td>
                        <td class="value-expense"><strong>{{ formatRp(branchData?.saldo_per_kategori_top?.total_pengeluaran) }}</strong></td>
                      </tr>
                    </tfoot>
                  </table>
                </div>
              </div>
            </div>
          </div>

          <div class="card" style="margin-top:14px">
            <div class="panel-title" style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:10px">
              <span>Log Transaksi Terakhir</span>
              <button class="btn btn-primary btn-sm" type="button" @click="go('transactions')">+ Transaksi</button>
            </div>
            <div class="table-wrap">
              <table>
                <thead><tr><th class="col-no">No</th><th>Tanggal</th><th>Kategori</th><th>Akun</th><th>Nominal</th></tr></thead>
                <tbody>
                  <tr v-for="(t, idx) in (branchData?.transaksi_terakhir || [])" :key="t.id">
                    <td class="col-no">{{ rowNo(idx) }}</td>
                    <td>{{ t.transaction_date?.slice?.(0,10) || t.transaction_date }}</td>
                    <td>{{ t.category?.name }}</td>
                    <td>{{ t.account?.name || '—' }}</td>
                    <td :style="{color: t.category?.type==='income' ? '#10B981' : '#EF4444'}">{{ formatRp(t.amount) }}</td>
                  </tr>
                  <tr v-if="!(branchData?.transaksi_terakhir || []).length">
                    <td colspan="5">Belum ada transaksi.</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </section>

        <!-- TRANSACTIONS PAGE -->
        <section v-if="page==='transactions'">
          <div class="page-head">
            <div>
              <h2 class="brand">Transaksi</h2>
              <p>Catat pemasukan & pengeluaran</p>
            </div>
          </div>
          <div v-if="periodLocked" class="banner-lock">
            Periode Pembukuan Bulan Ini Telah Dikunci Oleh Owner. Anda Tidak Dapat Menambah atau Mengubah Data.
          </div>

          <div class="card card-tx-form">
            <div class="panel-title">Form Transaksi</div>
            <div class="tx-form">
              <div class="tx-form-top">
                <div class="field tx-type-field">
                  <label>Tipe</label>
                  <div class="type-toggle">
                    <button type="button" class="type-btn" :class="{ active: txForm.type==='income' }" @click="txForm.type='income'">Pemasukan</button>
                    <button type="button" class="type-btn" :class="{ active: txForm.type==='expense' }" @click="txForm.type='expense'">Pengeluaran</button>
                  </div>
                </div>
                <div v-if="isOwner" class="field">
                  <label>Cabang</label>
                  <select v-model="txForm.branch_id">
                    <option disabled value="">Pilih cabang</option>
                    <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                  </select>
                </div>
              </div>

              <div class="tx-form-main">
                <div class="field">
                  <label>Kategori</label>
                  <select v-model="txForm.category_id">
                    <option disabled value="">Pilih kategori</option>
                    <option v-for="c in filteredCategories" :key="c.id" :value="c.id">{{ c.name }}</option>
                  </select>
                </div>
                <div class="field">
                  <label>Akun</label>
                  <select v-model="txForm.account_id">
                    <option disabled value="">Pilih akun</option>
                    <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.name }}</option>
                  </select>
                </div>
                <div class="field">
                  <label>Nominal</label>
                  <input :value="txForm.amount" @input="onAmountInput($event, txForm)" inputmode="numeric" placeholder="Ketik nominal" />
                </div>
                <div class="field">
                  <label>Tanggal</label>
                  <input type="date" v-model="txForm.transaction_date" />
                </div>
              </div>

              <div class="tx-form-bottom">
                <div class="field tx-desc-field">
                  <label>Deskripsi <span class="opt">(opsional)</span></label>
                  <textarea rows="2" v-model="txForm.description" placeholder="Catatan singkat…"></textarea>
                </div>
                <button class="btn btn-primary btn-tx-save" :disabled="periodLocked || loading" @click="submitTransaction">Simpan Transaksi</button>
              </div>
            </div>
          </div>

          <div class="card" style="margin-top:14px">
            <div class="panel-title">Daftar Transaksi</div>
            <div class="filter-bar">
              <div class="field">
                <label>Tipe</label>
                <select v-model="txFilter.type" @change="onTxFilterTypeChange">
                  <option value="">Semua</option>
                  <option value="income">Pemasukan</option>
                  <option value="expense">Pengeluaran</option>
                </select>
              </div>
              <div class="field">
                <label>Kategori</label>
                <select v-model="txFilter.category_id" @change="applyTxFilters">
                  <option value="">Semua kategori</option>
                  <option v-for="c in filterCategories" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
              </div>
              <div class="field">
                <label>Dari</label>
                <input type="date" v-model="txFilter.date_from" @change="applyTxFilters" />
              </div>
              <div class="field">
                <label>Sampai</label>
                <input type="date" v-model="txFilter.date_to" @change="applyTxFilters" />
              </div>
              <div class="field field-search">
                <label>Cari</label>
                <input v-model="txFilter.q" @input="onTxSearchInput" placeholder="Keterangan atau nominal…" />
              </div>
              <div class="field field-actions">
                <label>&nbsp;</label>
                <button class="btn btn-ghost" type="button" @click="resetTxFilters">Reset</button>
              </div>
            </div>
            <div class="filter-meta">{{ txMeta.total }} transaksi ditemukan</div>
            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th class="col-no">No</th>
                    <th>Tanggal</th>
                    <th>Cabang</th>
                    <th>Kategori</th>
                    <th>Akun</th>
                    <th>Nominal</th>
                    <th>Keterangan</th>
                    <th v-if="isOwner">Input Oleh</th>
                    <th v-if="isOwner">Dibuat</th>
                    <th v-if="isOwner">Diubah Oleh</th>
                    <th v-if="isOwner">Diubah</th>
                    <th v-if="!periodLocked">Aksi</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(t, idx) in transactions" :key="t.id">
                    <td class="col-no">{{ rowNo(idx, txMeta.current_page, txMeta.per_page) }}</td>
                    <td>{{ (t.transaction_date || '').toString().slice(0,10) }}</td>
                    <td>{{ t.branch?.name }}</td>
                    <td>{{ t.category?.name }}</td>
                    <td>{{ t.account?.name || '—' }}</td>
                    <td class="col-amount" :style="{color: t.category?.type==='income' ? '#10B981' : '#EF4444'}">{{ formatRp(t.amount) }}</td>
                    <td class="desc-cell">{{ t.description || '—' }}</td>
                    <td v-if="isOwner" class="col-audit">{{ t.user?.name || '—' }}</td>
                    <td v-if="isOwner" class="col-audit">{{ formatDateTime(t.created_at) }}</td>
                    <td v-if="isOwner" class="col-audit" :class="{'col-empty': !(t.updated_by?.name || t.updatedBy?.name)}">{{ t.updated_by?.name || t.updatedBy?.name || '—' }}</td>
                    <td v-if="isOwner" class="col-audit" :class="{'col-empty': !(t.updated_by?.name || t.updatedBy?.name)}">{{ (t.updated_by?.name || t.updatedBy?.name) ? formatDateTime(t.updated_at) : '—' }}</td>
                    <td v-if="!periodLocked">
                      <button class="btn btn-ghost btn-sm" @click="openEditTx(t)">Edit</button>
                      <button class="btn btn-danger btn-sm" @click="deleteTransaction(t.id)">Hapus</button>
                    </td>
                  </tr>
                  <tr v-if="!transactions.length">
                    <td :colspan="isOwner ? (periodLocked ? 11 : 12) : (periodLocked ? 7 : 8)">Tidak ada transaksi sesuai filter.</td>
                  </tr>
                </tbody>
              </table>
            </div>
            <div v-if="txMeta.last_page > 1" class="pager">
              <button class="btn btn-ghost btn-sm" :disabled="txMeta.current_page <= 1 || loading" @click="loadTransactions(txMeta.current_page - 1)">Sebelumnya</button>
              <span>Halaman {{ txMeta.current_page }} / {{ txMeta.last_page }}</span>
              <button class="btn btn-ghost btn-sm" :disabled="txMeta.current_page >= txMeta.last_page || loading" @click="loadTransactions(txMeta.current_page + 1)">Berikutnya</button>
            </div>
          </div>
        </section>

        <!-- TRANSFERS -->
        <section v-if="page==='transfers'">
          <div class="page-head">
            <div>
              <h2 class="brand">Transfer Cabang</h2>
              <p>Pindah dana dari satu cabang ke cabang lain</p>
            </div>
          </div>
          <div class="grid-2">
            <div class="card">
              <div class="panel-title">Form Pengajuan</div>
              <div class="form-grid">
                <div v-if="isOwner" class="field">
                  <label>Cabang Asal</label>
                  <select v-model="transferForm.from_branch_id">
                    <option disabled value="">Pilih</option>
                    <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                  </select>
                </div>
                <div class="field">
                  <label>Cabang Tujuan</label>
                  <select v-model="transferForm.to_branch_id">
                    <option disabled value="">Pilih</option>
                    <option v-for="b in destinationBranches" :key="b.id" :value="b.id">{{ b.name }}</option>
                  </select>
                </div>
                <div class="field">
                  <label>Akun</label>
                  <select v-model="transferForm.account_id">
                    <option disabled value="">Pilih akun</option>
                    <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.name }}</option>
                  </select>
                </div>
                <div class="field">
                  <label>Nominal</label>
                  <input :value="transferForm.amount" @input="onAmountInput($event, transferForm)" inputmode="numeric" />
                </div>
                <div class="field">
                  <label>Alasan keperluan</label>
                  <textarea rows="2" v-model="transferForm.reason" placeholder="Opsional"></textarea>
                </div>
                <button class="btn btn-warn" @click="submitTransferRequest">Kirim Pengajuan</button>
              </div>
            </div>
            <div class="card">
              <div class="panel-title">Status Pengajuan</div>
              <div v-for="t in (isOwner ? (ownerData?.transfer_pending || transfers) : transfers)" :key="t.id" style="margin-bottom:10px">
                <span class="badge badge-pending">PENDING</span>
                <div style="margin-top:6px;font-size:.9rem">
                  {{ t.from_branch?.name || t.fromBranch?.name || '-' }} → {{ t.to_branch?.name || t.toBranch?.name || '-' }}
                  · {{ t.account?.name || '—' }}
                  · {{ formatRp(t.amount) }}
                </div>
              </div>
              <div v-if="!(isOwner ? ownerData?.transfer_pending?.length : transfers.length)" style="color:#64748B">Belum ada pengajuan.</div>
            </div>
          </div>
        </section>

        <!-- RECON -->
        <section v-if="page==='recon'">
          <div class="page-head">
            <div>
              <h2 class="brand">Rekonsiliasi</h2>
              <p>Cocokkan saldo fisik vs sistem per akun</p>
            </div>
          </div>
          <div class="card" style="max-width:520px">
            <div class="form-grid">
              <div v-if="isOwner" class="field">
                <label>Cabang</label>
                <select v-model="reconForm.branch_id" @change="onReconBranchChange">
                  <option disabled value="">Pilih cabang</option>
                  <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                </select>
              </div>
              <div class="field">
                <label>Akun</label>
                <select v-model="reconForm.account_id" @change="onReconAccountOrDateChange">
                  <option disabled value="">Pilih akun</option>
                  <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.name }}</option>
                </select>
              </div>
              <div class="field">
                <label>Tanggal</label>
                <input type="date" v-model="reconForm.reconciliation_date" @change="onReconAccountOrDateChange" />
              </div>
              <div class="field">
                <label>Saldo Sistem ({{ accounts.find(a => a.id === Number(reconForm.account_id))?.name || 'akun' }})</label>
                <input :value="formatRp(reconSystemBalance)" disabled />
              </div>
              <div class="field">
                <label>Saldo Fisik</label>
                <input :value="reconForm.physical_balance" @input="onPhysicalInput" inputmode="numeric" placeholder="Masukkan saldo fisik akun ini" />
              </div>
              <div class="diff-preview" :class="Math.abs(reconDifference) < 0.01 ? 'diff-zero' : 'diff-nonzero'">
                Selisih: {{ formatRp(reconDifference) }}
              </div>
              <button class="btn btn-primary" :disabled="loading || !reconForm.account_id" @click="submitReconciliation">Simpan Rekonsiliasi</button>
            </div>
          </div>

          <div v-if="(branchData?.rekonsiliasi_hari_ini || []).length" class="card" style="max-width:720px;margin-top:14px">
            <div class="panel-title">Rekonsiliasi hari ini</div>
            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th class="col-no">No.</th>
                    <th>Akun</th>
                    <th>Sistem</th>
                    <th>Fisik</th>
                    <th>Selisih</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(r, idx) in branchData.rekonsiliasi_hari_ini" :key="r.id">
                    <td class="col-no">{{ rowNo(idx) }}</td>
                    <td>{{ r.account?.name || '-' }}</td>
                    <td>{{ formatRp(r.system_balance) }}</td>
                    <td>{{ formatRp(r.physical_balance) }}</td>
                    <td :class="Math.abs(Number(r.difference)) < 0.01 ? 'value-income' : 'value-expense'">
                      {{ formatRp(r.difference) }}
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </section>

        <!-- ADMIN: AKUN CABANG -->
        <section v-if="page==='branch-accounts' && isAdmin">
          <div class="page-head">
            <div>
              <h2 class="brand">Akun Cabang</h2>
              <p>Tambah akun dan atur saldo awal untuk {{ user?.branch?.name || 'cabang Anda' }}.</p>
            </div>
          </div>
          <div class="grid-2">
            <div class="card">
              <div class="panel-title">Tambah Akun</div>
              <p style="color:#64748B;font-size:.85rem;margin:0 0 10px">
                Jika kode sudah ada di sistem, akun itu dipasang ke cabang Anda (tidak membuat duplikat).
              </p>
              <div class="form-grid">
                <div class="field">
                  <label>Nama</label>
                  <input v-model="accountForm.name" @input="onAccountNameInput" placeholder="Contoh: BCA" />
                </div>
                <div class="field">
                  <label>Kode</label>
                  <input v-model="accountForm.code" placeholder="bca" />
                </div>
                <button class="btn btn-primary" :disabled="loading" @click="submitAccount">Simpan ke Cabang</button>
              </div>
            </div>
            <div class="card">
              <div class="panel-title">Saldo Awal</div>
              <p style="color:#64748B;font-size:.85rem;margin:0 0 10px">
                Saldo sistem = saldo awal + transaksi sejak tanggal mulai.
              </p>
              <div class="form-grid">
                <div class="field">
                  <label>Akun</label>
                  <select v-model="openingForm.account_id" @change="editOpeningForAccount(openingForm.account_id)">
                    <option disabled value="">Pilih akun</option>
                    <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.name }}</option>
                  </select>
                </div>
                <div class="field">
                  <label>Saldo Awal</label>
                  <input :value="openingForm.amount" @input="onAmountInput($event, openingForm, 'amount')" inputmode="numeric" placeholder="0" />
                </div>
                <div class="field">
                  <label>Berlaku Sejak</label>
                  <input type="date" v-model="openingForm.effective_date" />
                </div>
                <button class="btn btn-primary" :disabled="loading || !openingForm.account_id" @click="submitOpeningBalance">Simpan Saldo Awal</button>
              </div>
            </div>
          </div>
          <div class="card" style="margin-top:14px">
            <div class="panel-title">Akun di Cabang Ini</div>
            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th class="col-no">No</th>
                    <th>Nama</th>
                    <th>Kode</th>
                    <th>Saldo Awal</th>
                    <th>Sejak</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(a, idx) in accounts" :key="a.id">
                    <td class="col-no">{{ rowNo(idx) }}</td>
                    <td>{{ a.name }}</td>
                    <td><code>{{ a.code }}</code></td>
                    <td>{{ openingAmountFor(a.id) != null ? formatRp(openingAmountFor(a.id)) : '—' }}</td>
                    <td>{{ openingDateFor(a.id) ? formatDate(openingDateFor(a.id)) : '—' }}</td>
                    <td>
                      <button class="btn btn-ghost btn-sm" @click="editOpeningForAccount(a.id)">Atur</button>
                    </td>
                  </tr>
                  <tr v-if="!accounts.length"><td colspan="6">Belum ada akun di cabang ini.</td></tr>
                </tbody>
              </table>
            </div>
          </div>
        </section>

        <!-- INTERNAL TRANSFER -->
        <section v-if="page==='internal-transfer'">
          <div class="page-head">
            <div>
              <h2 class="brand">Transfer Antar Akun</h2>
              <p>Pindah saldo antar akun dalam satu cabang (contoh: Cash ke Mandiri)</p>
            </div>
          </div>
          <div v-if="periodLocked" class="banner-lock">
            Periode Pembukuan Bulan Ini Telah Dikunci Oleh Owner. Anda Tidak Dapat Menambah atau Mengubah Data.
          </div>
          <div class="card" style="max-width:520px">
            <div class="form-grid">
              <div v-if="isOwner" class="field">
                <label>Cabang</label>
                <select v-model="internalTransferForm.branch_id">
                  <option disabled value="">Pilih cabang</option>
                  <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                </select>
              </div>
              <div class="field">
                <label>Akun Asal</label>
                <select v-model="internalTransferForm.from_account_id">
                  <option disabled value="">Pilih akun</option>
                  <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.name }}</option>
                </select>
              </div>
              <div class="field">
                <label>Akun Tujuan</label>
                <select v-model="internalTransferForm.to_account_id">
                  <option disabled value="">Pilih akun</option>
                  <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.name }}</option>
                </select>
              </div>
              <div class="field">
                <label>Nominal</label>
                <input :value="internalTransferForm.amount" @input="onAmountInput($event, internalTransferForm)" inputmode="numeric" placeholder="Ketik nominal" />
              </div>
              <div class="field">
                <label>Tanggal</label>
                <input type="date" v-model="internalTransferForm.transaction_date" />
              </div>
              <div class="field">
                <label>Deskripsi</label>
                <textarea rows="2" v-model="internalTransferForm.description"></textarea>
              </div>
              <button class="btn btn-primary" :disabled="periodLocked || loading" @click="submitInternalTransfer">Simpan Transfer</button>
            </div>
          </div>
        </section>

        <!-- ADJUSTMENTS -->
        <section v-if="page==='adjustments' && isOwner">
          <div class="page-head">
            <div>
              <h2 class="brand">Penyesuaian Saldo</h2>
              <p>Jurnal penyesuaian oleh Pemilik</p>
            </div>
          </div>
          <div class="card" style="max-width:520px">
            <div class="form-grid">
              <div class="field">
                <label>Cabang</label>
                <select v-model="adjustmentForm.branch_id">
                  <option disabled value="">Pilih cabang</option>
                  <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                </select>
              </div>
              <div class="field">
                <label>Akun</label>
                <select v-model="adjustmentForm.account_id">
                  <option disabled value="">Pilih akun</option>
                  <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.name }}</option>
                </select>
              </div>
              <div class="radio-row">
                <div class="radio-pill" :class="{'active-income': adjustmentForm.type==='income'}" @click="adjustmentForm.type='income'">Pemasukan</div>
                <div class="radio-pill" :class="{'active-expense': adjustmentForm.type==='expense'}" @click="adjustmentForm.type='expense'">Pengeluaran</div>
              </div>
              <div class="field">
                <label>Nominal</label>
                <input :value="adjustmentForm.amount" @input="onAmountInput($event, adjustmentForm)" inputmode="numeric" placeholder="Ketik nominal" />
              </div>
              <div class="field">
                <label>Alasan (wajib)</label>
                <textarea rows="3" v-model="adjustmentForm.reason" placeholder="Jelaskan alasan penyesuaian"></textarea>
              </div>
              <div class="field">
                <label>Tanggal</label>
                <input type="date" v-model="adjustmentForm.transaction_date" />
              </div>
              <button class="btn btn-primary" :disabled="loading" @click="submitAdjustment">Simpan Penyesuaian</button>
            </div>
          </div>
        </section>

        <!-- SERVICE RECORDS -->
        <section v-if="page==='services'">
          <div class="page-head">
            <div>
              <h2 class="brand">Catatan Servis</h2>
              <p v-if="canInputService">Input servis cabang — total dihitung otomatis (harga − modal)</p>
              <p v-else-if="isOwner">Pantau catatan servis semua cabang (tanpa input)</p>
              <p v-else>Tipe cabang ini tidak menggunakan modul Service</p>
            </div>
          </div>

          <div v-if="isWorkshopBranch && isAdmin" class="banner-lock">
            Tipe cabang ini dikecualikan dari input catatan servis.
          </div>

          <div v-if="canInputService" id="service-form-card" class="card card-tx-form">
            <div class="panel-title">{{ serviceForm.id ? 'Ubah Catatan Servis' : 'Tambah Catatan Servis' }}</div>
            <div class="tx-form">
              <div class="tx-form-main">
                <div class="field">
                  <label>Tanggal</label>
                  <input type="date" v-model="serviceForm.service_date" />
                </div>
                <div class="field">
                  <label>Teknisi</label>
                  <select v-model="serviceForm.employee_id">
                    <option disabled value="">{{ serviceTechnicians.length ? 'Pilih teknisi' : 'Belum ada teknisi di cabang' }}</option>
                    <option v-for="e in serviceTechnicians" :key="e.id" :value="e.id">{{ e.name }}</option>
                  </select>
                  <small v-if="!serviceTechnicians.length" class="muted">Hanya karyawan aktif berjabatan Teknisi di cabang Anda. Minta Owner set jabatan di Data Karyawan.</small>
                </div>
                <div class="field">
                  <label>Merek</label>
                  <input v-model="serviceForm.brand" placeholder="OPPO, VIVO, …" />
                </div>
                <div class="field">
                  <label>Type</label>
                  <input v-model="serviceForm.device_type" placeholder="A96, Y20, …" />
                </div>
              </div>
              <div class="tx-form-main">
                <div class="field">
                  <label>Kerusakan</label>
                  <input v-model="serviceForm.damage" placeholder="LCD, IC, …" />
                </div>
                <div class="field">
                  <label>Modal</label>
                  <input :value="serviceForm.cost" @input="onAmountInput($event, serviceForm, 'cost')" inputmode="numeric" placeholder="Ketik nominal" />
                </div>
                <div class="field">
                  <label>Harga</label>
                  <input :value="serviceForm.price" @input="onAmountInput($event, serviceForm, 'price')" inputmode="numeric" placeholder="Ketik nominal" />
                </div>
                <div class="field">
                  <label>Total (otomatis)</label>
                  <input :value="formatRp(serviceProfitPreview)" disabled />
                </div>
              </div>
              <div class="tx-form-bottom">
                <div class="field" style="flex:1">
                  <label>Catatan</label>
                  <input v-model="serviceForm.notes" placeholder="Opsional" />
                </div>
                <div style="display:flex;gap:8px;align-items:flex-end">
                  <button v-if="serviceForm.id" class="btn btn-ghost" type="button" @click="resetServiceForm">Batal</button>
                  <button class="btn btn-primary btn-tx-save" :disabled="loading" @click="submitService">
                    {{ serviceForm.id ? 'Perbarui' : 'Simpan' }}
                  </button>
                </div>
              </div>
            </div>
          </div>

          <div class="card" :style="canInputService ? 'margin-top:14px' : ''">
            <div class="panel-title">Daftar Servis</div>
            <div class="filter-bar">
              <div v-if="isOwner" class="field">
                <label>Cabang</label>
                <select v-model="serviceFilter.branch_id" @change="loadServiceRecords">
                  <option value="">Semua cabang</option>
                  <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                </select>
              </div>
              <div class="field">
                <label>Dari</label>
                <input type="date" v-model="serviceFilter.date_from" @change="loadServiceRecords" />
              </div>
              <div class="field">
                <label>Sampai</label>
                <input type="date" v-model="serviceFilter.date_to" @change="loadServiceRecords" />
              </div>
              <div class="field field-search">
                <label>Cari</label>
                <input v-model="serviceFilter.q" @keyup.enter="loadServiceRecords" placeholder="Merek, type, kerusakan…" />
              </div>
              <div class="field field-actions">
                <label>&nbsp;</label>
                <button class="btn btn-ghost" type="button" @click="loadServiceRecords">Cari</button>
              </div>
            </div>

            <div class="grid-4" style="margin-bottom:12px">
              <div class="card metric">
                <div class="label">Jumlah</div>
                <div class="value" style="font-size:1.2rem">{{ serviceSummary.jumlah || 0 }}</div>
              </div>
              <div class="card metric">
                <div class="label">Total Modal</div>
                <div class="value value-expense" style="font-size:1.1rem">{{ formatRp(serviceSummary.total_modal) }}</div>
              </div>
              <div class="card metric">
                <div class="label">Total Harga</div>
                <div class="value value-income" style="font-size:1.1rem">{{ formatRp(serviceSummary.total_harga) }}</div>
              </div>
              <div class="card metric">
                <div class="label">Total Profit</div>
                <div class="value" style="font-size:1.1rem">{{ formatRp(serviceSummary.total_profit) }}</div>
              </div>
            </div>

            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th class="col-no">No</th>
                    <th>Tanggal</th>
                    <th v-if="isOwner">Cabang</th>
                    <th>Teknisi</th>
                    <th>Merek</th>
                    <th>Type</th>
                    <th>Kerusakan</th>
                    <th>Modal</th>
                    <th>Harga</th>
                    <th>Total</th>
                    <th v-if="canInputService">Aksi</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(r, idx) in serviceRecords" :key="r.id">
                    <td class="col-no">{{ rowNo(idx) }}</td>
                    <td>{{ (r.service_date || '').toString().slice(0,10) }}</td>
                    <td v-if="isOwner">{{ r.branch?.name }}</td>
                    <td>{{ r.employee?.name || '—' }}</td>
                    <td>{{ r.brand }}</td>
                    <td>{{ r.device_type }}</td>
                    <td>{{ r.damage }}</td>
                    <td>{{ formatRp(r.cost) }}</td>
                    <td class="value-income">{{ formatRp(r.price) }}</td>
                    <td style="font-weight:600">{{ formatRp(r.profit) }}</td>
                    <td v-if="canInputService">
                      <button class="btn btn-ghost btn-sm" @click="editService(r)">Edit</button>
                      <button class="btn btn-danger btn-sm" @click="deleteService(r.id)">Hapus</button>
                    </td>
                  </tr>
                  <tr v-if="!serviceRecords.length">
                    <td :colspan="isOwner ? (canInputService ? 11 : 10) : (canInputService ? 10 : 9)">Belum ada catatan servis.</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </section>

        <!-- EMPLOYEES (owner) -->
        <section v-if="page==='employees' && isOwner">
          <div class="page-head">
            <div>
              <h2 class="brand">Data Karyawan</h2>
              <p>Setiap karyawan terikat ke satu cabang — data antar cabang terpisah</p>
            </div>
          </div>

          <div id="employee-form-card" class="card card-tx-form">
            <div class="panel-title">{{ employeeForm.id ? 'Ubah Karyawan' : 'Tambah Karyawan' }}</div>
            <div class="tx-form">
              <div class="tx-form-main">
                <div class="field">
                  <label>Cabang <span class="opt">(wajib)</span></label>
                  <select v-model="employeeForm.branch_id">
                    <option disabled value="">Pilih cabang karyawan</option>
                    <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                  </select>
                </div>
                <div class="field">
                  <label>Nama <span class="opt">(wajib)</span></label>
                  <input v-model="employeeForm.name" placeholder="Nama lengkap" />
                </div>
                <div class="field">
                  <label>Telepon <span class="opt">(wajib)</span></label>
                  <input v-model="employeeForm.phone" placeholder="08xxxxxxxxxx" />
                </div>
                <div class="field" style="grid-column: 1 / -1">
                  <label>Jabatan <span class="opt">(boleh lebih dari satu)</span></label>
                  <div class="position-pills">
                    <button
                      v-for="opt in employeePositionOptions"
                      :key="opt.value"
                      type="button"
                      class="position-pill"
                      :class="{ active: employeeForm.positions.includes(opt.value) }"
                      @click="toggleEmployeePosition(opt.value)"
                    >{{ opt.label }}</button>
                  </div>
                </div>
              </div>
              <div class="tx-form-main">
                <div class="field">
                  <label>Status</label>
                  <select v-model="employeeForm.status">
                    <option value="active">Aktif</option>
                    <option value="inactive">Nonaktif</option>
                  </select>
                </div>
                <div class="field">
                  <label>Tanggal Masuk</label>
                  <input type="date" v-model="employeeForm.joined_at" />
                </div>
                <div class="field" style="grid-column: span 2">
                  <label>Catatan</label>
                  <input v-model="employeeForm.notes" placeholder="Opsional" />
                </div>
              </div>
              <div class="tx-form-bottom">
                <div></div>
                <div style="display:flex;gap:8px;justify-content:flex-end">
                  <button v-if="employeeForm.id" class="btn btn-ghost" type="button" @click="resetEmployeeForm">Batal</button>
                  <button class="btn btn-primary btn-tx-save" :disabled="loading" @click="submitEmployee">
                    {{ employeeForm.id ? 'Perbarui' : 'Simpan Karyawan' }}
                  </button>
                </div>
              </div>
            </div>
          </div>

          <div class="card" style="margin-top:14px">
            <div class="panel-title">Daftar Karyawan ({{ employees.length }})</div>
            <div class="filter-bar">
              <div class="field">
                <label>Cabang</label>
                <select v-model="employeeFilter.branch_id" @change="loadEmployees">
                  <option value="">Semua cabang</option>
                  <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                </select>
              </div>
              <div class="field">
                <label>Status</label>
                <select v-model="employeeFilter.status" @change="loadEmployees">
                  <option value="">Semua</option>
                  <option value="active">Aktif</option>
                  <option value="inactive">Nonaktif</option>
                </select>
              </div>
              <div class="field field-search">
                <label>Cari</label>
                <input v-model="employeeFilter.q" @keyup.enter="loadEmployees" placeholder="Nama, telepon, jabatan…" />
              </div>
              <div class="field field-actions">
                <label>&nbsp;</label>
                <button class="btn btn-ghost" type="button" @click="loadEmployees">Cari</button>
              </div>
            </div>

            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th class="col-no">No</th>
                    <th>Nama</th>
                    <th>Cabang</th>
                    <th>Jabatan</th>
                    <th>Telepon</th>
                    <th>Status</th>
                    <th>Aksi</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(e, idx) in employees" :key="e.id">
                    <td class="col-no">{{ rowNo(idx) }}</td>
                    <td><strong>{{ e.name }}</strong></td>
                    <td>{{ e.branch?.name || '—' }}</td>
                    <td>{{ formatEmployeePositions(e) }}</td>
                    <td>{{ e.phone || '—' }}</td>
                    <td>
                      <span class="badge" :class="e.status==='active' ? 'badge-approved' : 'badge-rejected'">
                        {{ e.status==='active' ? 'Aktif' : 'Nonaktif' }}
                      </span>
                    </td>
                    <td>
                      <button class="btn btn-ghost btn-sm" type="button" @click="editEmployee(e)">Edit</button>
                      <button class="btn btn-danger btn-sm" type="button" @click="deleteEmployee(e.id)">Hapus</button>
                    </td>
                  </tr>
                  <tr v-if="!employees.length">
                    <td colspan="7">Belum ada data karyawan.</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </section>

        <!-- TARGET CLOSINGAN (owner + admin konter) -->
        <section v-if="page==='closings' && canAccessClosings">
          <div class="page-head">
            <div>
              <h2 class="brand">Target Closingan</h2>
              <p>Input closing harian &amp; target bulanan karyawan konter (non-promotor)</p>
            </div>
          </div>

          <div class="card">
            <div class="filter-bar">
              <div v-if="isOwner" class="field">
                <label>Cabang</label>
                <select v-model="closingFilter.branch_id" @change="onClosingFilterChange">
                  <option value="">Semua konter</option>
                  <option v-for="b in konterBranches" :key="b.id" :value="b.id">{{ b.name }}</option>
                </select>
              </div>
              <div class="field">
                <label>Bulan</label>
                <select v-model.number="closingFilter.month" @change="onClosingFilterChange">
                  <option v-for="m in 12" :key="m" :value="m">{{ m }}</option>
                </select>
              </div>
              <div class="field">
                <label>Tahun</label>
                <select v-model.number="closingFilter.year" @change="onClosingFilterChange">
                  <option v-for="y in closingYears" :key="y" :value="y">{{ y }}</option>
                </select>
              </div>
              <div class="field field-actions">
                <label>&nbsp;</label>
                <button class="btn btn-ghost" type="button" :disabled="loading" @click="loadClosingBoard">Muat Ulang</button>
              </div>
            </div>
            <div class="filter-meta">
              Total closing: <strong>{{ closingBoard.meta?.grand_total ?? 0 }}</strong>
              · Target: <strong>{{ closingBoard.meta?.grand_target ?? 0 }}</strong>
              <span v-if="closingBoard.meta?.grand_target">
                · Capaian:
                <strong>
                  {{ closingBoard.meta.grand_target ? (Math.round((closingBoard.meta.grand_total / closingBoard.meta.grand_target) * 1000) / 10).toFixed(1) + '%' : '—' }}
                </strong>
              </span>
            </div>

            <div class="table-wrap closing-board-wrap">
              <table class="closing-board">
                <thead>
                  <tr>
                    <th class="col-sticky">Nama</th>
                    <th v-if="isOwner && !closingFilter.branch_id" class="col-sticky-2">Cabang</th>
                    <th v-for="d in closingDays" :key="'h'+d" class="col-day">{{ d }}</th>
                    <th>Total</th>
                    <th>Target</th>
                    <th>%</th>
                  </tr>
                </thead>
                <tbody>
                  <template v-for="(g, gi) in closingBoard.groups" :key="'g'+gi">
                    <tr v-if="isOwner && !closingFilter.branch_id" class="closing-group-row">
                      <td :colspan="(isOwner && !closingFilter.branch_id ? 2 : 1) + closingDays.length + 3">
                        <strong>{{ g.branch_name }}</strong>
                        <span class="muted"> · subtotal {{ g.branch_total }}</span>
                      </td>
                    </tr>
                    <tr v-for="row in g.rows" :key="row.employee_id">
                      <td class="col-sticky"><strong>{{ row.name }}</strong></td>
                      <td v-if="isOwner && !closingFilter.branch_id" class="col-sticky-2">{{ row.branch_name }}</td>
                      <td v-for="d in closingDays" :key="row.employee_id+'-'+d" class="col-day">
                        <input
                          class="closing-cell"
                          type="text"
                          inputmode="numeric"
                          :value="row.daily[d] || ''"
                          @focus="onClosingFocus"
                          @keydown="onClosingKeydown"
                          @change="saveClosingDaily(row, d, $event.target.value, $event.target)"
                        />
                      </td>
                      <td><strong>{{ row.total }}</strong></td>
                      <td>
                        <input
                          class="closing-cell closing-target"
                          type="text"
                          inputmode="numeric"
                          :value="row.target || ''"
                          @focus="onClosingFocus"
                          @keydown="onClosingKeydown"
                          @change="saveClosingTarget(row, $event.target.value, $event.target)"
                        />
                      </td>
                      <td :class="closingPctClass(row.pct)">
                        {{ row.pct != null ? Number(row.pct).toFixed(1) + '%' : '—' }}
                      </td>
                    </tr>
                    <tr class="closing-subtotal-row">
                      <td class="col-sticky"><strong>Total {{ g.branch_name }}</strong></td>
                      <td v-if="isOwner && !closingFilter.branch_id" class="col-sticky-2">—</td>
                      <td v-for="d in closingDays" :key="'sub-'+gi+'-'+d" class="col-day">
                        <strong>{{ g.daily_totals?.[d] || '' }}</strong>
                      </td>
                      <td><strong>{{ g.branch_total }}</strong></td>
                      <td><strong>{{ g.branch_target }}</strong></td>
                      <td>
                        <strong :class="closingPctClass(g.branch_target ? Math.round((g.branch_total / g.branch_target) * 10000) / 100 : null)">
                          {{ g.branch_target ? (Math.round((g.branch_total / g.branch_target) * 1000) / 10).toFixed(1) + '%' : '—' }}
                        </strong>
                      </td>
                    </tr>
                  </template>
                  <tr v-if="(closingBoard.groups || []).length > 1" class="closing-grand-row">
                    <td class="col-sticky"><strong>TOTAL SEMUA</strong></td>
                    <td v-if="isOwner && !closingFilter.branch_id" class="col-sticky-2">—</td>
                    <td v-for="d in closingDays" :key="'grand-'+d" class="col-day">
                      <strong>{{ closingBoard.meta?.daily_totals?.[d] || '' }}</strong>
                    </td>
                    <td><strong>{{ closingBoard.meta?.grand_total ?? 0 }}</strong></td>
                    <td><strong>{{ closingBoard.meta?.grand_target ?? 0 }}</strong></td>
                    <td>
                      <strong>
                        {{ closingBoard.meta?.grand_target
                          ? (Math.round((closingBoard.meta.grand_total / closingBoard.meta.grand_target) * 1000) / 10).toFixed(1) + '%'
                          : '—' }}
                      </strong>
                    </td>
                  </tr>
                  <tr v-if="!(closingBoard.groups || []).length">
                    <td :colspan="(isOwner && !closingFilter.branch_id ? 2 : 1) + closingDays.length + 3">
                      Belum ada karyawan aktif di konter untuk periode ini.
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
            <p class="closing-hint">
              Setiap perubahan akan diminta konfirmasi Simpan/Batal, lalu muncul notifikasi.
              Esc membatalkan sebelum konfirmasi. Baris hijau = total harian per cabang.
            </p>
          </div>
        </section>

        <!-- ABSENSI (owner + semua admin inkl. bengkel) -->
        <section v-if="page==='attendance' && canAccessAttendance">
          <div class="page-head">
            <div>
              <h2 class="brand">Absensi Karyawan</h2>
              <p>Input harian Hadir / Izin / Sakit / Alpha, plus rekap bulanan</p>
            </div>
          </div>

          <div class="tab-row">
            <button class="tab-btn" :class="{active: attendanceTab==='daily'}" @click="switchAttendanceTab('daily')">Harian</button>
            <button class="tab-btn" :class="{active: attendanceTab==='monthly'}" @click="switchAttendanceTab('monthly')">Bulanan</button>
          </div>

          <div v-if="attendanceTab==='daily'" class="card" style="margin-top:14px">
            <div class="filter-bar">
              <div v-if="isOwner" class="field">
                <label>Cabang</label>
                <select v-model="attendanceFilter.branch_id" @change="onAttendanceFilterChange">
                  <option value="">Semua cabang</option>
                  <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                </select>
              </div>
              <div class="field">
                <label>Tanggal</label>
                <input type="date" v-model="attendanceDailyDate" @change="loadAttendanceDaily" />
              </div>
              <div class="field field-actions">
                <label>&nbsp;</label>
                <button class="btn btn-ghost" type="button" :disabled="loading" @click="loadAttendanceDaily">Muat Ulang</button>
              </div>
            </div>

            <div class="filter-meta">
              Ringkas:
              <strong class="att-present">H {{ attendanceDailyCounts.present }}</strong>
              · <strong class="att-leave">I {{ attendanceDailyCounts.leave }}</strong>
              · <strong class="att-sick">S {{ attendanceDailyCounts.sick }}</strong>
              · <strong class="att-absent">A {{ attendanceDailyCounts.absent }}</strong>
              <span v-if="attendanceDailyCounts.empty"> · belum isi {{ attendanceDailyCounts.empty }}</span>
            </div>

            <div class="att-actions">
              <button class="btn btn-ghost btn-sm" type="button" :disabled="loading || !attendanceDailyRows.length" @click="markAllAttendancePresent">Tandai semua Hadir</button>
              <button class="btn btn-ghost btn-sm" type="button" :disabled="loading || !attendanceDailyRows.length" @click="copyYesterdayAttendance">Salin kemarin</button>
              <button class="btn btn-primary" type="button" :disabled="loading || !attendanceDailyRows.length" @click="saveAttendanceDaily">Simpan Absensi</button>
            </div>

            <div class="table-wrap">
              <table class="att-daily-table">
                <thead>
                  <tr>
                    <th class="col-no">No</th>
                    <th>Nama</th>
                    <th v-if="isOwner && !attendanceFilter.branch_id">Cabang</th>
                    <th>Status</th>
                    <th>Catatan</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(row, idx) in attendanceDailyRows" :key="row.employee_id">
                    <td class="col-no">{{ rowNo(idx) }}</td>
                    <td><strong>{{ row.name }}</strong></td>
                    <td v-if="isOwner && !attendanceFilter.branch_id">{{ row.branch_name || '—' }}</td>
                    <td>
                      <div class="att-radio-row">
                        <label v-for="opt in attendanceStatusOptions" :key="opt.value" class="att-radio" :class="{active: row.status===opt.value, ['att-'+opt.value]: true}">
                          <input type="radio" :name="'att-'+row.employee_id" :value="opt.value" v-model="row.status" />
                          <span>{{ opt.label }}</span>
                        </label>
                      </div>
                    </td>
                    <td>
                      <input class="att-note" type="text" v-model="row.note" maxlength="255" placeholder="Opsional" />
                    </td>
                  </tr>
                  <tr v-if="!attendanceDailyRows.length">
                    <td :colspan="isOwner && !attendanceFilter.branch_id ? 5 : 4">Tidak ada karyawan aktif (Owner/PIC disembunyikan).</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <div v-else class="card" style="margin-top:14px">
            <div class="filter-bar">
              <div v-if="isOwner" class="field">
                <label>Cabang</label>
                <select v-model="attendanceFilter.branch_id" @change="onAttendanceFilterChange">
                  <option value="">Semua cabang</option>
                  <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                </select>
              </div>
              <div class="field">
                <label>Bulan</label>
                <select v-model.number="attendanceFilter.month" @change="onAttendanceFilterChange">
                  <option v-for="m in 12" :key="m" :value="m">{{ m }}</option>
                </select>
              </div>
              <div class="field">
                <label>Tahun</label>
                <select v-model.number="attendanceFilter.year" @change="onAttendanceFilterChange">
                  <option v-for="y in attendanceYears" :key="y" :value="y">{{ y }}</option>
                </select>
              </div>
              <div class="field field-actions">
                <label>&nbsp;</label>
                <button class="btn btn-ghost" type="button" :disabled="loading" @click="loadAttendanceBoard">Muat Ulang</button>
              </div>
            </div>
            <div class="filter-meta">
              Total:
              <strong class="att-present">H {{ attendanceBoard.meta?.counts?.present ?? 0 }}</strong>
              · <strong class="att-leave">I {{ attendanceBoard.meta?.counts?.leave ?? 0 }}</strong>
              · <strong class="att-sick">S {{ attendanceBoard.meta?.counts?.sick ?? 0 }}</strong>
              · <strong class="att-absent">A {{ attendanceBoard.meta?.counts?.absent ?? 0 }}</strong>
            </div>

            <div class="table-wrap closing-board-wrap">
              <table class="closing-board att-board">
                <thead>
                  <tr>
                    <th class="col-sticky">Nama</th>
                    <th v-if="isOwner && !attendanceFilter.branch_id" class="col-sticky-2">Cabang</th>
                    <th v-for="d in attendanceDays" :key="'ah'+d" class="col-day">{{ d }}</th>
                    <th>H</th>
                    <th>I</th>
                    <th>S</th>
                    <th>A</th>
                  </tr>
                </thead>
                <tbody>
                  <template v-for="(g, gi) in attendanceBoard.groups" :key="'ag'+gi">
                    <tr v-if="isOwner && !attendanceFilter.branch_id" class="closing-group-row">
                      <td :colspan="(isOwner && !attendanceFilter.branch_id ? 2 : 1) + attendanceDays.length + 4">
                        <strong>{{ g.branch_name }}</strong>
                        <span class="muted">
                          · H {{ g.counts?.present ?? 0 }}
                          · I {{ g.counts?.leave ?? 0 }}
                          · S {{ g.counts?.sick ?? 0 }}
                          · A {{ g.counts?.absent ?? 0 }}
                        </span>
                      </td>
                    </tr>
                    <tr v-for="row in g.rows" :key="'ar'+row.employee_id">
                      <td class="col-sticky"><strong>{{ row.name }}</strong></td>
                      <td v-if="isOwner && !attendanceFilter.branch_id" class="col-sticky-2">{{ row.branch_name }}</td>
                      <td
                        v-for="d in attendanceDays"
                        :key="row.employee_id+'-a'+d"
                        class="col-day"
                        :class="[attendanceCellClass(row.daily[d]), isOwner ? 'att-cell-editable' : '']"
                      >
                        <select
                          v-if="isOwner"
                          class="att-board-select"
                          :class="attendanceCellClass(row.daily[d])"
                          :value="row.daily[d] || ''"
                          :disabled="loading"
                          @change="onAttendanceBoardCellChange(row, d, $event)"
                        >
                          <option value="">—</option>
                          <option value="present">H</option>
                          <option value="leave">I</option>
                          <option value="sick">S</option>
                          <option value="absent">A</option>
                        </select>
                        <template v-else>{{ attendanceShort(row.daily[d]) }}</template>
                      </td>
                      <td><strong class="att-present">{{ row.counts?.present ?? 0 }}</strong></td>
                      <td><strong class="att-leave">{{ row.counts?.leave ?? 0 }}</strong></td>
                      <td><strong class="att-sick">{{ row.counts?.sick ?? 0 }}</strong></td>
                      <td><strong class="att-absent">{{ row.counts?.absent ?? 0 }}</strong></td>
                    </tr>
                  </template>
                  <tr v-if="!(attendanceBoard.groups || []).length">
                    <td :colspan="(isOwner && !attendanceFilter.branch_id ? 2 : 1) + attendanceDays.length + 4">
                      Belum ada karyawan aktif untuk periode ini.
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
            <p class="closing-hint">
              <template v-if="isOwner">Owner dapat mengubah langsung di sel board. Pilih — untuk mengosongkan. </template>
              <template v-else>Sel baca-saja. Untuk mengubah, gunakan tab Harian. </template>
              H=Hadir, I=Izin, S=Sakit, A=Alpha.
            </p>
          </div>
        </section>

        <!-- GAJI (owner only) -->
        <section v-if="page==='payroll' && canAccessPayroll">
          <div class="page-head">
            <div>
              <h2 class="brand">Gaji Konter</h2>
              <p>Rekap bulanan konter: Gapok, Insentif HP/ACC, Service, bonus, hutang &amp; pengeluaran</p>
            </div>
          </div>

          <div class="card">
            <div class="filter-bar">
              <div v-if="isOwner" class="field">
                <label>Cabang</label>
                <select v-model="payrollFilter.branch_id" @change="onPayrollFilterChange">
                  <option value="">Semua cabang</option>
                  <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                </select>
              </div>
              <div class="field">
                <label>Bulan</label>
                <select v-model.number="payrollFilter.month" @change="onPayrollFilterChange">
                  <option v-for="m in 12" :key="m" :value="m">{{ m }}</option>
                </select>
              </div>
              <div class="field">
                <label>Tahun</label>
                <select v-model.number="payrollFilter.year" @change="onPayrollFilterChange">
                  <option v-for="y in payrollYears" :key="y" :value="y">{{ y }}</option>
                </select>
              </div>
              <div class="field field-actions">
                <label>&nbsp;</label>
                <div class="att-actions" style="margin:0">
                  <button class="btn btn-ghost btn-sm" type="button" :disabled="loading" @click="loadPayrollBoard">Hitung Ulang</button>
                  <button class="btn btn-primary btn-sm" type="button" :disabled="loading || payrollBoard.meta?.all_locked" @click="savePayrollBoard">Simpan</button>
                  <button class="btn btn-danger btn-sm" type="button" :disabled="loading || payrollBoard.meta?.all_locked" @click="lockPayrollBoard">Kunci</button>
                  <button v-if="isOwner && payrollBoard.meta?.any_locked" class="btn btn-ghost btn-sm" type="button" :disabled="loading" @click="unlockPayrollBoard">Buka Kunci</button>
                </div>
              </div>
            </div>

            <div class="filter-meta">
              {{ payrollMonthLabel }}
              · Total bersih: <strong>{{ formatRp(payrollBoard.meta?.totals?.grand_total || 0) }}</strong>
              · Gapok <strong>{{ formatRp(payrollBoard.meta?.totals?.gapok || 0) }}</strong>
              · HP <strong>{{ formatRp(payrollBoard.meta?.totals?.insentif_hp || 0) }}</strong>
              · Service <strong>{{ formatRp(payrollBoard.meta?.totals?.service_incentive || 0) }}</strong>
              <span v-if="payrollBoard.meta?.all_locked"> · <span class="badge badge-rejected">Terkunci</span></span>
              <span v-else-if="payrollBoard.meta?.any_locked"> · <span class="badge badge-pending">Sebagian terkunci</span></span>
            </div>

            <div class="table-wrap closing-board-wrap">
              <table class="closing-board payroll-board">
                <thead>
                  <tr>
                    <th class="col-sticky">Nama</th>
                    <th v-if="isOwner && !payrollFilter.branch_id" class="col-sticky-2">Cabang</th>
                    <th>Jabatan</th>
                    <th>Hadir</th>
                    <th>Gapok</th>
                    <th>Qty</th>
                    <th>Insentif HP</th>
                    <th>Service 50%</th>
                    <th>Insentif ACC</th>
                    <th>Bonus Absen</th>
                    <th>Hutang</th>
                    <th>Pengeluaran</th>
                    <th>Total</th>
                    <th>Status</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  <template v-for="(g, gi) in payrollBoard.groups" :key="'pg'+gi">
                    <tr v-if="isOwner && !payrollFilter.branch_id" class="closing-group-row">
                      <td :colspan="isOwner && !payrollFilter.branch_id ? 15 : 14">
                        <strong>{{ g.branch_name }}</strong>
                        <span class="muted"> · {{ formatRp(g.totals?.grand_total || 0) }}</span>
                      </td>
                    </tr>
                    <tr v-for="row in g.rows" :key="'pr'+row.employee_id">
                      <td class="col-sticky"><strong>{{ row.name }}</strong></td>
                      <td v-if="isOwner && !payrollFilter.branch_id" class="col-sticky-2">{{ row.branch_name }}</td>
                      <td>{{ row.position || '—' }}</td>
                      <td>{{ row.present_days }}</td>
                      <td>{{ formatRp(row.gapok) }}</td>
                      <td>{{ row.closing_qty }}</td>
                      <td>{{ formatRp(row.insentif_hp) }}</td>
                      <td>{{ formatRp(row.service_incentive) }}</td>
                      <td>
                        <input
                          class="payroll-cell"
                          type="text"
                          inputmode="numeric"
                          :disabled="row.status==='locked' || loading"
                          :value="formatInputNumber(row.insentif_acc)"
                          @focus="onPayrollFocus"
                          @keydown="onPayrollKeydown"
                          @input="onPayrollManualInput(row, 'insentif_acc', $event)"
                        />
                      </td>
                      <td>
                        <input
                          class="payroll-cell"
                          type="text"
                          inputmode="numeric"
                          :disabled="row.status==='locked' || loading"
                          :value="formatInputNumber(row.bonus_absen)"
                          @focus="onPayrollFocus"
                          @keydown="onPayrollKeydown"
                          @input="onPayrollManualInput(row, 'bonus_absen', $event)"
                        />
                      </td>
                      <td>
                        <input
                          class="payroll-cell"
                          type="text"
                          inputmode="numeric"
                          :disabled="row.status==='locked' || loading"
                          :value="formatInputNumber(row.hutang)"
                          @focus="onPayrollFocus"
                          @keydown="onPayrollKeydown"
                          @input="onPayrollManualInput(row, 'hutang', $event)"
                        />
                      </td>
                      <td>
                        <input
                          class="payroll-cell"
                          type="text"
                          inputmode="numeric"
                          :disabled="row.status==='locked' || loading"
                          :value="formatInputNumber(row.pengeluaran)"
                          @focus="onPayrollFocus"
                          @keydown="onPayrollKeydown"
                          @input="onPayrollManualInput(row, 'pengeluaran', $event)"
                        />
                      </td>
                      <td><strong>{{ formatRp(row.total) }}</strong></td>
                      <td>
                        <span class="badge" :class="row.status==='locked' ? 'badge-rejected' : 'badge-approved'">
                          {{ row.status==='locked' ? 'Terkunci' : 'Draf' }}
                        </span>
                      </td>
                      <td>
                        <button class="btn btn-ghost btn-sm" type="button" @click="openPayrollDetail(row)">Detail</button>
                      </td>
                    </tr>
                    <tr class="closing-subtotal-row">
                      <td class="col-sticky"><strong>Total {{ g.branch_name }}</strong></td>
                      <td v-if="isOwner && !payrollFilter.branch_id" class="col-sticky-2">—</td>
                      <td colspan="2">—</td>
                      <td><strong>{{ formatRp(g.totals?.gapok || 0) }}</strong></td>
                      <td>{{ g.totals?.closing_qty || 0 }}</td>
                      <td><strong>{{ formatRp(g.totals?.insentif_hp || 0) }}</strong></td>
                      <td><strong>{{ formatRp(g.totals?.service_incentive || 0) }}</strong></td>
                      <td><strong>{{ formatRp(g.totals?.insentif_acc || 0) }}</strong></td>
                      <td><strong>{{ formatRp(g.totals?.bonus_absen || 0) }}</strong></td>
                      <td><strong>{{ formatRp(g.totals?.hutang || 0) }}</strong></td>
                      <td><strong>{{ formatRp(g.totals?.pengeluaran || 0) }}</strong></td>
                      <td><strong>{{ formatRp(g.totals?.grand_total || 0) }}</strong></td>
                      <td colspan="2"></td>
                    </tr>
                  </template>
                  <tr v-if="!(payrollBoard.groups || []).length">
                    <td :colspan="isOwner && !payrollFilter.branch_id ? 15 : 14">
                      Belum ada karyawan aktif untuk periode ini.
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
            <p class="closing-hint">
              Gapok = Hadir × Rp50.000 (promotor = 0). Insentif HP = closing × Rp10.000.
              Service 50% hanya jabatan Teknisi. Kolom Acc/Bonus/Hutang/Pengeluaran diisi manual.
            </p>
          </div>
        </section>

        <!-- UPAH BENGKEL (owner + admin bengkel) -->
        <section v-if="page==='workshop-wages' && canAccessWorkshopWages">
          <div class="page-head">
            <div>
              <h2 class="brand">Upah Kerja Bengkel</h2>
              <p>Input kerja harian &amp; bayar mingguan (Senin) — beda dari Gaji Konter</p>
            </div>
          </div>

          <div class="tab-row">
            <button class="tab-btn" :class="{active: wwTab==='weekly'}" @click="switchWwTab('weekly')">Mingguan</button>
            <button class="tab-btn" :class="{active: wwTab==='daily'}" @click="switchWwTab('daily')">Harian</button>
            <button class="tab-btn" :class="{active: wwTab==='settings'}" @click="switchWwTab('settings')">Pengaturan %</button>
          </div>

          <div class="card" style="margin-top:14px">
            <div class="filter-bar">
              <div v-if="isOwner" class="field">
                <label>Cabang bengkel</label>
                <select v-model="wwFilter.branch_id" @change="onWwFilterChange">
                  <option value="">Pilih cabang</option>
                  <option v-for="b in workshopBranches" :key="b.id" :value="b.id">{{ b.name }}</option>
                </select>
              </div>
              <template v-if="wwTab!=='daily'">
                <div class="field">
                  <label>Bulan</label>
                  <select v-model.number="wwFilter.month" @change="onWwFilterChange">
                    <option v-for="m in 12" :key="m" :value="m">{{ m }}</option>
                  </select>
                </div>
                <div class="field">
                  <label>Tahun</label>
                  <select v-model.number="wwFilter.year" @change="onWwFilterChange">
                    <option v-for="y in wwYears" :key="y" :value="y">{{ y }}</option>
                  </select>
                </div>
              </template>
              <div v-if="wwTab==='daily'" class="field">
                <label>Tanggal</label>
                <input type="date" v-model="wwDailyDate" @change="loadWwDailyJobs" />
              </div>
            </div>

            <div v-if="isOwner && !wwFilter.branch_id" class="empty-hint">
              Pilih cabang bengkel untuk melanjutkan.
            </div>

            <template v-else-if="wwTab==='daily'">
              <div class="grid-2">
                <div>
                  <div class="panel-title">{{ wwJobForm.id ? 'Ubah Kerja' : 'Tambah Kerja' }}</div>
                  <div class="form-grid">
                    <div class="field">
                      <label>Tanggal</label>
                      <input type="date" v-model="wwJobForm.job_date" />
                    </div>
                    <div class="field">
                      <label>Teknisi</label>
                      <select v-model="wwJobForm.employee_id">
                        <option disabled value="">Pilih teknisi</option>
                        <option v-for="t in wwTechnicians" :key="t.id" :value="t.id">{{ t.name }}</option>
                      </select>
                    </div>
                    <div class="field">
                      <label>Jenis kerja</label>
                      <input list="ww-job-types" v-model="wwJobForm.job_type" placeholder="ONGKER" />
                      <datalist id="ww-job-types">
                        <option v-for="jt in wwJobTypes" :key="jt" :value="jt"></option>
                      </datalist>
                    </div>
                    <div class="field">
                      <label>Nominal (penuh)</label>
                      <input :value="wwJobForm.amount" @input="onAmountInput($event, wwJobForm)" inputmode="numeric" placeholder="0" />
                    </div>
                    <div class="field">
                      <label>Catatan</label>
                      <input v-model="wwJobForm.note" placeholder="Opsional" />
                    </div>
                    <div style="display:flex;gap:8px">
                      <button class="btn btn-primary" style="flex:1" :disabled="loading" @click="submitWwJob">
                        {{ wwJobForm.id ? 'Perbarui' : 'Simpan' }}
                      </button>
                      <button v-if="wwJobForm.id" class="btn btn-ghost" @click="resetWwJobForm">Batal</button>
                    </div>
                  </div>
                </div>
                <div>
                  <div class="panel-title">Kerja {{ wwDailyDate }}</div>
                  <div class="table-wrap">
                    <table>
                      <thead>
                        <tr><th>Teknisi</th><th>Jenis</th><th>Nominal</th><th></th></tr>
                      </thead>
                      <tbody>
                        <tr v-for="j in wwJobs" :key="j.id">
                          <td>{{ j.employee_name }}</td>
                          <td>{{ j.job_type }}</td>
                          <td>{{ formatRp(j.amount) }}</td>
                          <td>
                            <button class="btn btn-ghost btn-sm" @click="editWwJob(j)">Edit</button>
                            <button class="btn btn-danger btn-sm" @click="deleteWwJob(j)">Hapus</button>
                          </td>
                        </tr>
                        <tr v-if="!wwJobs.length"><td colspan="4">Belum ada kerja di tanggal ini.</td></tr>
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>
            </template>

            <template v-else-if="wwTab==='weekly'">
              <div class="filter-bar">
                <div class="field" style="min-width:280px">
                  <label>Minggu (Senin–Minggu)</label>
                  <select v-model="wwFilter.week_start" @change="onWwWeekSelect">
                    <option v-for="w in wwWeeks" :key="w.week_start" :value="w.week_start">
                      {{ w.label }} · {{ w.status==='paid' ? 'Lunas' : 'Belum' }}
                    </option>
                  </select>
                </div>
                <div class="field field-actions">
                  <label>&nbsp;</label>
                  <div class="att-actions" style="margin:0">
                    <button class="btn btn-primary btn-sm" type="button" :disabled="loading || wwWeekDetail?.status==='paid'" @click="payWwWeek">Tandai Lunas</button>
                    <button v-if="isOwner && wwWeekDetail?.status==='paid'" class="btn btn-ghost btn-sm" type="button" :disabled="loading" @click="reopenWwWeek">Buka Lagi</button>
                  </div>
                </div>
              </div>

              <div v-if="wwWeekDetail" class="filter-meta">
                {{ wwWeekDetail.label }}
                · {{ wwWeekDetail.pay_hint }}
                · Kotor <strong>{{ formatRp(wwWeekDetail.totals?.gross || 0) }}</strong>
                · Upah teknisi <strong>{{ formatRp(wwWeekDetail.totals?.tech_net || 0) }}</strong>
                · Bengkel <strong>{{ formatRp(wwWeekDetail.totals?.shop_share || 0) }}</strong>
                ·
                <span class="badge" :class="wwWeekDetail.status==='paid' ? 'badge-approved' : 'badge-pending'">
                  {{ wwWeekDetail.status==='paid' ? 'Lunas' : 'Belum lunas' }}
                </span>
              </div>

              <div class="table-wrap" style="margin-top:10px">
                <table>
                  <thead>
                    <tr><th>Teknisi</th><th>Job</th><th>Kotor</th><th>%</th><th>Upah bersih</th></tr>
                  </thead>
                  <tbody>
                    <tr v-for="t in (wwWeekDetail?.technicians || [])" :key="t.employee_id">
                      <td><strong>{{ t.name }}</strong></td>
                      <td>{{ t.job_count }}</td>
                      <td>{{ formatRp(t.gross) }}</td>
                      <td>{{ t.tech_share_pct }}%</td>
                      <td><strong>{{ formatRp(t.net) }}</strong></td>
                    </tr>
                    <tr v-if="!(wwWeekDetail?.technicians || []).length">
                      <td colspan="5">Belum ada kerja di minggu ini.</td>
                    </tr>
                  </tbody>
                </table>
              </div>

              <div class="panel-title" style="margin-top:16px">Rincian kerja minggu ini</div>
              <div class="table-wrap">
                <table>
                  <thead>
                    <tr><th>Tanggal</th><th>Teknisi</th><th>Jenis</th><th>Nominal</th></tr>
                  </thead>
                  <tbody>
                    <tr v-for="j in (wwWeekDetail?.jobs || [])" :key="j.id">
                      <td>{{ formatDate(j.job_date) }}</td>
                      <td>{{ j.employee_name }}</td>
                      <td>{{ j.job_type }}</td>
                      <td>{{ formatRp(j.amount) }}</td>
                    </tr>
                    <tr v-if="!(wwWeekDetail?.jobs || []).length">
                      <td colspan="4">Tidak ada data.</td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <p class="closing-hint">
                Upah dibayar tiap Senin untuk kerja Senin–Minggu minggu sebelumnya.
                Minggu yang sudah lunas mengunci edit/hapus pekerjaan di rentang itu.
              </p>
            </template>

            <template v-else>
              <p class="closing-hint">
                Atur bagian teknisi (%) per orang untuk bulan {{ wwFilter.month }}/{{ wwFilter.year }}.
                Default {{ wwSettingsMeta?.default_tech_share_pct ?? 50 }}% jika belum disimpan.
                Bagian bengkel = 100% − bagian teknisi (masing-masing).
              </p>
              <div class="table-wrap">
                <table>
                  <thead>
                    <tr>
                      <th>Teknisi</th>
                      <th>Jabatan</th>
                      <th>Bagian teknisi (%)</th>
                      <th>Bagian bengkel (%)</th>
                      <th>Status</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="r in wwSettingsRows" :key="r.employee_id">
                      <td><strong>{{ r.name }}</strong></td>
                      <td>{{ r.position || '—' }}</td>
                      <td style="max-width:120px">
                        <input
                          type="number"
                          min="0"
                          max="100"
                          step="0.01"
                          v-model.number="r.tech_share_pct"
                          style="width:100%;padding:8px;border:1px solid var(--line);border-radius:8px"
                        />
                      </td>
                      <td>{{ Math.max(0, Math.round((100 - Number(r.tech_share_pct || 0)) * 100) / 100) }}%</td>
                      <td>
                        <span class="badge" :class="r.is_default ? 'badge-pending' : 'badge-approved'">
                          {{ r.is_default ? 'Default' : 'Custom' }}
                        </span>
                      </td>
                    </tr>
                    <tr v-if="!wwSettingsRows.length">
                      <td colspan="5">Belum ada teknisi aktif di cabang bengkel ini.</td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <div style="margin-top:12px">
                <button class="btn btn-primary" :disabled="loading || !wwSettingsRows.length" @click="saveWwSettings">
                  Simpan Persen Per Teknisi
                </button>
              </div>
            </template>
          </div>
        </section>

        <section v-if="page==='kelola' && isOwner">
          <div class="page-head">
            <div>
              <h2 class="brand">Kelola</h2>
              <p>Cabang, admin, dan kategori</p>
            </div>
          </div>
          <div class="tab-row">
            <button class="tab-btn" :class="{active: kelolaTab==='branches'}" @click="kelolaTab='branches'">Cabang</button>
            <button class="tab-btn" :class="{active: kelolaTab==='accounts'}" @click="kelolaTab='accounts'; loadAllAccounts()">Akun</button>
            <button class="tab-btn" :class="{active: kelolaTab==='admins'}" @click="kelolaTab='admins'">Admin</button>
            <button class="tab-btn" :class="{active: kelolaTab==='categories'}" @click="kelolaTab='categories'">Kategori</button>
          </div>

          <div v-if="kelolaTab==='branches'" style="margin-top:14px">
            <div class="grid-2">
              <div class="card">
                <div class="panel-title">{{ branchForm.id ? 'Ubah Cabang' : 'Tambah Cabang' }}</div>
                <div class="form-grid">
                  <div class="field">
                    <label>Nama</label>
                    <input v-model="branchForm.name" />
                  </div>
                  <div class="field">
                    <label>Tipe</label>
                    <select v-model="branchForm.type">
                      <option disabled value="">Pilih tipe</option>
                      <option v-for="t in activeBranchTypes" :key="t.code" :value="t.code">{{ t.name }}</option>
                    </select>
                  </div>
                  <div class="field">
                    <label>Alamat</label>
                    <textarea rows="2" v-model="branchForm.address"></textarea>
                  </div>
                  <div class="field">
                    <label>Status</label>
                    <select v-model="branchForm.status">
                      <option value="active">Aktif</option>
                      <option value="inactive">Nonaktif</option>
                    </select>
                  </div>
                  <div style="display:flex;gap:8px">
                    <button class="btn btn-primary" style="flex:1" @click="submitBranch">{{ branchForm.id ? 'Perbarui' : 'Simpan' }}</button>
                    <button v-if="branchForm.id" class="btn btn-ghost" @click="resetBranchForm">Batal</button>
                  </div>
                </div>
              </div>
              <div class="card">
                <div class="panel-title">Daftar Cabang</div>
                <div class="table-wrap">
                  <table>
                    <thead><tr><th class="col-no">No</th><th>Nama</th><th>Tipe</th><th>Status</th><th>Aksi</th></tr></thead>
                    <tbody>
                      <tr v-for="(b, idx) in branches" :key="b.id">
                        <td class="col-no">{{ rowNo(idx) }}</td>
                        <td>{{ b.name }}</td>
                        <td>{{ branchTypeLabel(b.type) }}</td>
                        <td><span class="badge" :class="b.status==='active' ? 'badge-approved' : 'badge-rejected'">{{ b.status==='active' ? 'Aktif' : 'Nonaktif' }}</span></td>
                        <td><button class="btn btn-ghost btn-sm" @click="editBranch(b)">Edit</button></td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>

            <div class="grid-2" style="margin-top:14px">
              <div class="card">
                <div class="panel-title">{{ branchTypeForm.id ? 'Ubah Tipe Cabang' : 'Tambah Tipe Cabang' }}</div>
                <p style="color:#64748B;font-size:.85rem;margin:0 0 12px">Saat ini: Konter &amp; Bengkel. Tambah tipe baru kapan saja dari sini.</p>
                <div class="form-grid">
                  <div class="field">
                    <label>Kode {{ branchTypeForm.id ? '(tidak diubah)' : '' }}</label>
                    <input v-model="branchTypeForm.code" :disabled="!!branchTypeForm.id" placeholder="contoh: gudang" />
                  </div>
                  <div class="field">
                    <label>Nama tampilan</label>
                    <input v-model="branchTypeForm.name" placeholder="Contoh: Gudang" />
                  </div>
                  <div class="field">
                    <label>Modul Service</label>
                    <label style="display:flex;align-items:center;gap:8px;font-weight:500;margin-top:6px">
                      <input type="checkbox" v-model="branchTypeForm.allows_service" />
                      Boleh input Catatan Servis
                    </label>
                  </div>
                  <div class="field">
                    <label>Status</label>
                    <select v-model="branchTypeForm.status">
                      <option value="active">Aktif</option>
                      <option value="inactive">Nonaktif</option>
                    </select>
                  </div>
                  <div style="display:flex;gap:8px">
                    <button class="btn btn-primary" style="flex:1" @click="submitBranchType">{{ branchTypeForm.id ? 'Perbarui' : 'Simpan Tipe' }}</button>
                    <button v-if="branchTypeForm.id" class="btn btn-ghost" @click="resetBranchTypeForm">Batal</button>
                  </div>
                </div>
              </div>
              <div class="card">
                <div class="panel-title">Daftar Tipe</div>
                <div class="table-wrap">
                  <table>
                    <thead><tr><th class="col-no">No</th><th>Kode</th><th>Nama</th><th>Service</th><th>Status</th><th>Aksi</th></tr></thead>
                    <tbody>
                      <tr v-for="(t, idx) in branchTypes" :key="t.id">
                        <td class="col-no">{{ rowNo(idx) }}</td>
                        <td><code>{{ t.code }}</code></td>
                        <td>{{ t.name }}</td>
                        <td>{{ t.allows_service ? 'Ya' : 'Tidak' }}</td>
                        <td><span class="badge" :class="t.status==='active' ? 'badge-approved' : 'badge-rejected'">{{ t.status==='active' ? 'Aktif' : 'Nonaktif' }}</span></td>
                        <td>
                          <button class="btn btn-ghost btn-sm" @click="editBranchType(t)">Edit</button>
                          <button class="btn btn-danger btn-sm" @click="deleteBranchType(t)">Hapus</button>
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          </div>

          <div v-if="kelolaTab==='accounts'" style="margin-top:14px">
            <!-- Daftar akun -->
            <div class="card" style="margin-bottom:14px">
              <div class="panel-title">Daftar Akun</div>
              <div class="form-inline">
                <div class="field">
                  <label>Nama</label>
                  <input v-model="accountForm.name" @input="onAccountNameInput" placeholder="Contoh: BCA" />
                </div>
                <div class="field">
                  <label>Kode</label>
                  <input v-model="accountForm.code" :disabled="!!accountForm.id" placeholder="bca" />
                </div>
                <div class="field">
                  <label>Status</label>
                  <select :value="accountForm.is_active ? '1' : '0'" @change="accountForm.is_active = ($event.target.value === '1')">
                    <option value="1">Aktif</option>
                    <option value="0">Nonaktif</option>
                  </select>
                </div>
                <div class="form-actions">
                  <button class="btn btn-primary" :disabled="loading" @click="submitAccount">
                    {{ accountForm.id ? 'Perbarui' : 'Tambah' }}
                  </button>
                  <button v-if="accountForm.id" class="btn btn-ghost" @click="resetAccountForm">Batal</button>
                </div>
              </div>
              <div class="table-wrap">
                <table>
                  <thead><tr><th class="col-no">No</th><th>Nama</th><th>Kode</th><th>Status</th><th>Aksi</th></tr></thead>
                  <tbody>
                    <tr v-for="(a, idx) in allAccounts" :key="a.id">
                      <td class="col-no">{{ rowNo(idx) }}</td>
                      <td>{{ a.name }}</td>
                      <td><code>{{ a.code }}</code></td>
                      <td>
                        <span class="badge" :class="a.is_active ? 'badge-approved' : 'badge-rejected'">
                          {{ a.is_active ? 'Aktif' : 'Nonaktif' }}
                        </span>
                      </td>
                      <td>
                        <button class="btn btn-ghost btn-sm" @click="editAccount(a)">Edit</button>
                        <button
                          class="btn btn-danger btn-sm"
                          :disabled="!!a.in_use"
                          :title="a.in_use ? 'Sudah digunakan' : 'Hapus'"
                          @click="deleteAccount(a)"
                        >Hapus</button>
                      </td>
                    </tr>
                    <tr v-if="!allAccounts.length"><td colspan="5">Belum ada akun.</td></tr>
                  </tbody>
                </table>
              </div>
            </div>

            <!-- Pengaturan cabang -->
            <div class="card">
              <div class="panel-head">
                <div class="panel-title">Pengaturan Cabang</div>
                <div class="field">
                  <label>Cabang</label>
                  <select v-model="accountAssignBranchId" @change="onBranchSetupChange">
                    <option value="">Pilih cabang</option>
                    <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }} ({{ branchTypeLabel(b.type) }})</option>
                  </select>
                </div>
              </div>

              <div v-if="!accountAssignBranchId" class="empty-hint">
                Pilih cabang untuk mengatur akun dan saldo awal.
              </div>

              <template v-else>
                <div class="table-wrap">
                  <table>
                    <thead>
                      <tr>
                        <th style="width:48px">Pakai</th>
                        <th>Akun</th>
                        <th>Saldo Awal</th>
                        <th>Berlaku Sejak</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-for="a in activeAllAccounts" :key="'setup-'+a.id">
                        <td>
                          <input
                            type="checkbox"
                            :checked="isBranchAccountSelected(a.id)"
                            @change="toggleBranchSetupAccount(a.id)"
                          />
                        </td>
                        <td>{{ a.name }} <code style="margin-left:6px;opacity:.7">{{ a.code }}</code></td>
                        <td>
                          <input
                            :value="ensureBranchSetupOpening(a.id).amount"
                            @input="onAmountInput($event, ensureBranchSetupOpening(a.id), 'amount')"
                            :disabled="!isBranchAccountSelected(a.id)"
                            inputmode="numeric"
                            placeholder="0"
                            style="min-width:140px"
                          />
                        </td>
                        <td>
                          <input
                            type="date"
                            v-model="ensureBranchSetupOpening(a.id).effective_date"
                            :disabled="!isBranchAccountSelected(a.id)"
                          />
                        </td>
                      </tr>
                      <tr v-if="!activeAllAccounts.length">
                        <td colspan="4">Belum ada akun aktif. Tambah akun di atas dulu.</td>
                      </tr>
                    </tbody>
                  </table>
                </div>
                <div style="margin-top:12px">
                  <button class="btn btn-primary" :disabled="loading" @click="saveBranchSetup">Simpan</button>
                </div>
              </template>
            </div>
          </div>

          <div v-if="kelolaTab==='admins'" class="grid-2" style="margin-top:14px">
            <div class="card">
              <div class="panel-title">{{ adminForm.id ? 'Ubah Admin' : 'Tambah Admin' }}</div>
              <div class="form-grid">
                <div class="field">
                  <label>Cabang</label>
                  <select v-model="adminForm.branch_id">
                    <option disabled value="">Pilih cabang</option>
                    <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                  </select>
                </div>
                <div class="field">
                  <label>Nama</label>
                  <input v-model="adminForm.name" />
                </div>
                <div class="field">
                  <label>Email</label>
                  <input v-model="adminForm.email" type="email" />
                </div>
                <div class="field">
                  <label>Kata sandi {{ adminForm.id ? '(kosongkan jika tidak diubah)' : '' }}</label>
                  <input v-model="adminForm.password" type="password" />
                </div>
                <div style="display:flex;gap:8px">
                  <button class="btn btn-primary" style="flex:1" @click="submitAdmin">{{ adminForm.id ? 'Perbarui' : 'Simpan' }}</button>
                  <button v-if="adminForm.id" class="btn btn-ghost" @click="resetAdminForm">Batal</button>
                </div>
              </div>
            </div>
            <div class="card">
              <div class="panel-title">Daftar Admin Cabang</div>
              <div class="table-wrap">
                <table>
                  <thead><tr><th class="col-no">No</th><th>Nama</th><th>Email</th><th>Cabang</th><th>Aksi</th></tr></thead>
                  <tbody>
                    <tr v-for="(a, idx) in admins" :key="a.id">
                      <td class="col-no">{{ rowNo(idx) }}</td>
                      <td>{{ a.name }}</td>
                      <td>{{ a.email }}</td>
                      <td>{{ a.branch?.name }}</td>
                      <td>
                        <button class="btn btn-ghost btn-sm" type="button" @click="editAdmin(a)">Edit</button>
                        <button class="btn btn-danger btn-sm" type="button" @click="deleteAdmin(a)">Hapus</button>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <div v-if="kelolaTab==='categories'" class="grid-2" style="margin-top:14px">
            <div class="card">
              <div class="panel-title">{{ categoryForm.id ? 'Ubah Kategori' : 'Tambah Kategori' }}</div>
              <p class="muted" style="margin:0 0 10px;font-size:.85rem">
                Owner dapat mengedit, menonaktifkan, dan mengubah cakupan kategori global/lokal
                (kecuali kategori sistem Transfer/Penyesuaian).
              </p>
              <div class="form-grid">
                <div class="field">
                  <label>Nama</label>
                  <input v-model="categoryForm.name" />
                </div>
                <div class="field">
                  <label>Cakupan</label>
                  <select v-model="categoryForm.branch_id">
                    <option value="">Global (semua cabang)</option>
                    <option v-for="b in branches" :key="b.id" :value="b.id">Lokal: {{ b.name }}</option>
                  </select>
                </div>
                <div class="radio-row">
                  <div
                    class="radio-pill"
                    :class="{'active-income': categoryForm.type==='income', disabled: !!categoryForm.id}"
                    @click="!categoryForm.id && (categoryForm.type='income')"
                  >Pemasukan</div>
                  <div
                    class="radio-pill"
                    :class="{'active-expense': categoryForm.type==='expense', disabled: !!categoryForm.id}"
                    @click="!categoryForm.id && (categoryForm.type='expense')"
                  >Pengeluaran</div>
                </div>
                <div v-if="categoryForm.id" class="field">
                  <label>Status</label>
                  <select :value="categoryForm.is_active ? '1' : '0'" @change="categoryForm.is_active = ($event.target.value === '1')">
                    <option value="1">Aktif</option>
                    <option value="0">Nonaktif</option>
                  </select>
                </div>
                <div style="display:flex;gap:8px">
                  <button class="btn btn-primary" style="flex:1" :disabled="loading" @click="submitCategory">
                    {{ categoryForm.id ? 'Perbarui' : 'Simpan Kategori' }}
                  </button>
                  <button v-if="categoryForm.id" class="btn btn-ghost" type="button" @click="resetCategoryForm">Batal</button>
                </div>
              </div>
            </div>
            <div class="card">
              <div class="panel-title">Daftar Kategori</div>
              <div class="table-wrap">
                <table>
                  <thead>
                    <tr>
                      <th class="col-no">No</th>
                      <th>Nama</th>
                      <th>Tipe</th>
                      <th>Cakupan</th>
                      <th>Status</th>
                      <th>Aksi</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="(c, idx) in categories" :key="c.id">
                      <td class="col-no">{{ rowNo(idx) }}</td>
                      <td>{{ c.name }}</td>
                      <td :style="{color: c.type==='income' ? '#10B981' : '#EF4444'}">{{ c.type==='income' ? 'Pemasukan' : 'Pengeluaran' }}</td>
                      <td>{{ categoryScopeLabel(c) }}</td>
                      <td>
                        <span class="badge" :class="c.is_active !== false ? 'badge-approved' : 'badge-rejected'">
                          {{ c.is_active !== false ? 'Aktif' : 'Nonaktif' }}
                        </span>
                      </td>
                      <td>
                        <template v-if="canManageCategory(c)">
                          <button class="btn btn-ghost btn-sm" type="button" @click="editCategory(c)">Edit</button>
                          <button class="btn btn-ghost btn-sm" type="button" @click="toggleCategoryActive(c)">
                            {{ c.is_active !== false ? 'Nonaktifkan' : 'Aktifkan' }}
                          </button>
                          <button class="btn btn-danger btn-sm" type="button" @click="deleteCategory(c)">Hapus</button>
                        </template>
                        <span v-else class="muted" style="font-size:.8rem">
                          {{ isSystemCategory(c) ? 'Sistem' : '—' }}
                        </span>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </section>

        <!-- ADMIN: KATEGORI CABANG -->
        <section v-if="page==='branch-categories' && isAdmin">
          <div class="page-head">
            <div>
              <h2 class="brand">Kategori</h2>
              <p>
                Kategori global hanya bisa diubah Owner (Kelola → Kategori).
                Anda mengelola kategori lokal
                {{ user?.branch?.name || 'cabang Anda' }}.
              </p>
            </div>
          </div>
          <div class="grid-2">
            <div class="card">
              <div class="panel-title">{{ categoryForm.id ? 'Ubah Kategori Lokal' : 'Tambah Kategori Lokal' }}</div>
              <div class="form-grid">
                <div class="field">
                  <label>Nama</label>
                  <input v-model="categoryForm.name" />
                </div>
                <div class="radio-row">
                  <div
                    class="radio-pill"
                    :class="{'active-income': categoryForm.type==='income', disabled: !!categoryForm.id}"
                    @click="!categoryForm.id && (categoryForm.type='income')"
                  >Pemasukan</div>
                  <div
                    class="radio-pill"
                    :class="{'active-expense': categoryForm.type==='expense', disabled: !!categoryForm.id}"
                    @click="!categoryForm.id && (categoryForm.type='expense')"
                  >Pengeluaran</div>
                </div>
                <div style="display:flex;gap:8px">
                  <button class="btn btn-primary" style="flex:1" :disabled="loading" @click="submitCategory">
                    {{ categoryForm.id ? 'Perbarui' : 'Simpan' }}
                  </button>
                  <button v-if="categoryForm.id" class="btn btn-ghost" type="button" @click="resetCategoryForm">Batal</button>
                </div>
              </div>
            </div>
            <div class="card">
              <div class="panel-title">Daftar Kategori</div>
              <div class="table-wrap">
                <table>
                  <thead>
                    <tr>
                      <th class="col-no">No</th>
                      <th>Nama</th>
                      <th>Tipe</th>
                      <th>Cakupan</th>
                      <th>Status</th>
                      <th>Aksi</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="(c, idx) in categories" :key="c.id">
                      <td class="col-no">{{ rowNo(idx) }}</td>
                      <td>{{ c.name }}</td>
                      <td :style="{color: c.type==='income' ? '#10B981' : '#EF4444'}">{{ c.type==='income' ? 'Pemasukan' : 'Pengeluaran' }}</td>
                      <td>
                        <span class="badge" :class="c.branch_id ? 'badge-pending' : 'badge-approved'">
                          {{ categoryScopeLabel(c) }}
                        </span>
                      </td>
                      <td>
                        <span class="badge" :class="c.is_active !== false ? 'badge-approved' : 'badge-rejected'">
                          {{ c.is_active !== false ? 'Aktif' : 'Nonaktif' }}
                        </span>
                      </td>
                      <td>
                        <template v-if="canManageCategory(c)">
                          <button class="btn btn-ghost btn-sm" type="button" @click="editCategory(c)">Edit</button>
                          <button class="btn btn-ghost btn-sm" type="button" @click="toggleCategoryActive(c)">
                            {{ c.is_active !== false ? 'Nonaktifkan' : 'Aktifkan' }}
                          </button>
                          <button class="btn btn-danger btn-sm" type="button" @click="deleteCategory(c)">Hapus</button>
                        </template>
                        <span v-else class="muted" style="font-size:.8rem">
                          {{ !c.branch_id ? 'Hanya Owner' : (isSystemCategory(c) ? 'Sistem' : '—') }}
                        </span>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </section>

        <!-- REPORTS -->
        <section v-if="page==='reports'">
          <div class="page-head">
            <div>
              <h2 class="brand">Laporan</h2>
              <p>Keuangan, konter, bengkel, absensi — lihat atau unduh PDF</p>
            </div>
          </div>

          <div class="report-type-grid">
            <button
              v-for="rt in reportTypes"
              :key="rt.id"
              type="button"
              class="report-type-card"
              :class="{active: reportForm.type===rt.id}"
              @click="selectReportType(rt.id)"
            >
              <strong>{{ rt.title }}</strong>
              <span>{{ rt.desc }}</span>
            </button>
          </div>

          <div class="card" style="margin-top:14px">
            <div class="panel-title">Filter Laporan</div>
            <div class="filter-bar">
              <div v-if="isOwner" class="field">
                <label>Cabang</label>
                <select v-model="reportForm.branch_id">
                  <option value="">Semua cabang</option>
                  <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                </select>
              </div>
              <div class="field">
                <label>Dari</label>
                <input type="date" v-model="reportForm.date_from" />
              </div>
              <div class="field">
                <label>Sampai</label>
                <input type="date" v-model="reportForm.date_to" />
              </div>
              <div v-if="reportForm.type==='ringkasan' || reportForm.type==='kategori' || reportForm.type==='transaksi'" class="field">
                <label>Tipe</label>
                <select v-model="reportForm.type_filter" @change="reportForm.category_id=''">
                  <option value="">Semua</option>
                  <option value="income">Pemasukan</option>
                  <option value="expense">Pengeluaran</option>
                </select>
              </div>
              <div v-if="reportForm.type==='kategori' || reportForm.type==='transaksi'" class="field">
                <label>Kategori</label>
                <select v-model="reportForm.category_id">
                  <option value="">Semua kategori</option>
                  <option v-for="c in reportFilterCategories" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
              </div>
              <div v-if="reportForm.type==='akun' || reportForm.type==='transaksi' || reportForm.type==='transfer' || reportForm.type==='rekonsiliasi'" class="field">
                <label>Akun</label>
                <select v-model="reportForm.account_id">
                  <option value="">Semua akun</option>
                  <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.name }}</option>
                </select>
              </div>
              <div v-if="reportForm.type==='gaji' || reportForm.type==='closing'" class="field" style="min-width:220px">
                <label>Catatan</label>
                <div class="filter-meta" style="padding-top:8px">Pakai bulan dari tanggal <strong>Dari</strong></div>
              </div>
              <div v-if="reportForm.type==='transaksi'" class="field field-search">
                <label>Cari</label>
                <input v-model="reportForm.q" placeholder="Keterangan atau nominal…" />
              </div>
              <div class="field field-actions">
                <label>&nbsp;</label>
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                  <button class="btn btn-primary" :disabled="loading" @click="loadReport">Tampilkan</button>
                  <button class="btn btn-ghost" :disabled="loading" @click="exportReportPdf('attachment')">Export PDF</button>
                  <button class="btn btn-ghost" :disabled="loading" @click="exportReportPdf('inline')">Buka PDF</button>
                </div>
              </div>
            </div>
          </div>

          <div v-if="reportResult" class="card" style="margin-top:14px">
            <div class="panel-title">{{ reportResult.meta?.judul || 'Hasil Laporan' }}</div>
            <div class="filter-meta" style="margin-bottom:12px">
              {{ reportResult.meta?.cabang }} · {{ reportResult.meta?.periode }}
            </div>

            <template v-if="reportForm.type==='ringkasan'">
              <div class="grid-4" style="margin-bottom:14px">
                <div class="card metric">
                  <div class="label">Pemasukan</div>
                  <div class="value value-income">{{ formatRp(reportResult.data?.ringkasan?.pemasukan) }}</div>
                </div>
                <div class="card metric">
                  <div class="label">Pengeluaran</div>
                  <div class="value value-expense">{{ formatRp(reportResult.data?.ringkasan?.pengeluaran) }}</div>
                </div>
                <div class="card metric">
                  <div class="label">Selisih</div>
                  <div class="value">{{ formatRp(reportResult.data?.ringkasan?.selisih) }}</div>
                </div>
                <div class="card metric">
                  <div class="label">Jumlah Trx</div>
                  <div class="value" style="font-size:1.3rem">
                    {{ (reportResult.data?.ringkasan?.jumlah_pemasukan || 0) + (reportResult.data?.ringkasan?.jumlah_pengeluaran || 0) }}
                  </div>
                </div>
              </div>
              <div class="table-wrap">
                <table>
                  <thead><tr><th class="col-no">No</th><th>Tanggal</th><th>Pemasukan</th><th>Pengeluaran</th><th>Selisih</th></tr></thead>
                  <tbody>
                    <tr v-for="(row, idx) in (reportResult.data?.harian || [])" :key="row.tanggal">
                      <td class="col-no">{{ rowNo(idx) }}</td>
                      <td>{{ row.tanggal }}</td>
                      <td class="value-income">{{ formatRp(row.pemasukan) }}</td>
                      <td class="value-expense">{{ formatRp(row.pengeluaran) }}</td>
                      <td>{{ formatRp(row.selisih) }}</td>
                    </tr>
                    <tr v-if="!(reportResult.data?.harian || []).length"><td colspan="5">Tidak ada data.</td></tr>
                  </tbody>
                </table>
              </div>
            </template>

            <template v-else-if="reportForm.type==='kategori'">
              <div class="table-wrap">
                <table>
                  <thead><tr><th class="col-no">No</th><th>Kategori</th><th>Tipe</th><th>Jumlah</th><th>Total</th></tr></thead>
                  <tbody>
                    <tr v-for="(row, idx) in (reportResult.data?.rows || [])" :key="row.category_id">
                      <td class="col-no">{{ rowNo(idx) }}</td>
                      <td>{{ row.nama }}</td>
                      <td>{{ row.tipe === 'income' ? 'Pemasukan' : 'Pengeluaran' }}</td>
                      <td>{{ row.jumlah }}</td>
                      <td :class="row.tipe==='income' ? 'value-income' : 'value-expense'">{{ formatRp(row.total) }}</td>
                    </tr>
                    <tr v-if="!(reportResult.data?.rows || []).length"><td colspan="5">Tidak ada data.</td></tr>
                  </tbody>
                </table>
              </div>
            </template>

            <template v-else-if="reportForm.type==='akun'">
              <template v-if="reportResult.data?.mode==='branch'">
                <div class="table-wrap">
                  <table>
                    <thead><tr><th class="col-no">No</th><th>Akun</th><th>Saldo</th></tr></thead>
                    <tbody>
                      <tr v-for="(row, idx) in (reportResult.data?.rows || [])" :key="row.account_id">
                        <td class="col-no">{{ rowNo(idx) }}</td>
                        <td>{{ row.nama_akun }}</td>
                        <td :style="{color: Number(row.saldo) >= 0 ? '#10B981' : '#EF4444', fontWeight:600}">{{ formatRp(row.saldo) }}</td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </template>
              <template v-else>
                <div v-for="branch in (reportResult.data?.rows || [])" :key="branch.branch_id" style="margin-bottom:16px">
                  <div class="panel-title" style="margin-bottom:8px">{{ branch.nama_cabang }}</div>
                  <div class="table-wrap">
                    <table>
                      <thead><tr><th class="col-no">No</th><th>Akun</th><th>Saldo</th></tr></thead>
                      <tbody>
                        <tr v-for="(row, idx) in branch.akun" :key="row.account_id">
                          <td class="col-no">{{ rowNo(idx) }}</td>
                          <td>{{ row.nama_akun }}</td>
                          <td>{{ formatRp(row.saldo) }}</td>
                        </tr>
                        <tr>
                          <td></td>
                          <td><strong>Total</strong></td>
                          <td><strong>{{ formatRp(branch.total_saldo) }}</strong></td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                </div>
              </template>
            </template>

            <template v-else-if="reportForm.type==='transaksi'">
              <div class="filter-meta">
                {{ reportResult.data?.jumlah || 0 }} transaksi ·
                Pemasukan {{ formatRp(reportResult.data?.total_pemasukan) }} ·
                Pengeluaran {{ formatRp(reportResult.data?.total_pengeluaran) }}
              </div>
              <div class="table-wrap">
                <table>
                  <thead>
                    <tr><th class="col-no">No</th><th>Tanggal</th><th>Cabang</th><th>Kategori</th><th>Akun</th><th>Nominal</th><th>Keterangan</th></tr>
                  </thead>
                  <tbody>
                    <tr v-for="(row, idx) in (reportResult.data?.rows || [])" :key="row.id">
                      <td class="col-no">{{ rowNo(idx) }}</td>
                      <td>{{ row.tanggal }}</td>
                      <td>{{ row.cabang }}</td>
                      <td>{{ row.kategori }}</td>
                      <td>{{ row.akun || '—' }}</td>
                      <td :class="row.tipe==='income' ? 'value-income' : 'value-expense'">{{ formatRp(row.nominal) }}</td>
                      <td class="desc-cell">{{ row.keterangan || '—' }}</td>
                    </tr>
                    <tr v-if="!(reportResult.data?.rows || []).length"><td colspan="7">Tidak ada data.</td></tr>
                  </tbody>
                </table>
              </div>
            </template>

            <template v-else-if="reportForm.type==='transfer'">
              <div class="filter-meta">
                {{ reportResult.data?.jumlah || 0 }} transfer · Total {{ formatRp(reportResult.data?.total_nominal) }}
                · Disetujui {{ reportResult.data?.approved || 0 }} · Pending {{ reportResult.data?.pending || 0 }}
              </div>
              <div class="table-wrap">
                <table>
                  <thead>
                    <tr><th class="col-no">No</th><th>Tanggal</th><th>Dari</th><th>Ke</th><th>Akun</th><th>Nominal</th><th>Status</th><th>Pemohon</th></tr>
                  </thead>
                  <tbody>
                    <tr v-for="(row, idx) in (reportResult.data?.rows || [])" :key="row.id">
                      <td class="col-no">{{ rowNo(idx) }}</td>
                      <td>{{ row.tanggal }}</td>
                      <td>{{ row.dari }}</td>
                      <td>{{ row.ke }}</td>
                      <td>{{ row.akun || '—' }}</td>
                      <td>{{ formatRp(row.nominal) }}</td>
                      <td><span class="badge" :class="'badge-' + row.status">{{ (row.status || '').toUpperCase() }}</span></td>
                      <td>{{ row.pemohon || '—' }}</td>
                    </tr>
                    <tr v-if="!(reportResult.data?.rows || []).length"><td colspan="8">Tidak ada data.</td></tr>
                  </tbody>
                </table>
              </div>
            </template>

            <template v-else-if="reportForm.type==='servis'">
              <div class="filter-meta">
                {{ reportResult.data?.jumlah || 0 }} job ·
                Omzet {{ formatRp(reportResult.data?.total_harga) }} ·
                Profit {{ formatRp(reportResult.data?.total_profit) }}
              </div>
              <div class="table-wrap">
                <table>
                  <thead>
                    <tr><th class="col-no">No</th><th>Tanggal</th><th>Cabang</th><th>Teknisi</th><th>Merek</th><th>Tipe</th><th>Modal</th><th>Harga</th><th>Profit</th></tr>
                  </thead>
                  <tbody>
                    <tr v-for="(row, idx) in (reportResult.data?.rows || [])" :key="row.id">
                      <td class="col-no">{{ rowNo(idx) }}</td>
                      <td>{{ row.tanggal }}</td>
                      <td>{{ row.cabang }}</td>
                      <td>{{ row.teknisi }}</td>
                      <td>{{ row.merek }}</td>
                      <td>{{ row.tipe }}</td>
                      <td>{{ formatRp(row.modal) }}</td>
                      <td class="value-income">{{ formatRp(row.harga) }}</td>
                      <td :class="Number(row.profit) >= 0 ? 'value-income' : 'value-expense'">{{ formatRp(row.profit) }}</td>
                    </tr>
                    <tr v-if="!(reportResult.data?.rows || []).length"><td colspan="9">Tidak ada data.</td></tr>
                  </tbody>
                </table>
              </div>
            </template>

            <template v-else-if="reportForm.type==='absensi'">
              <div class="filter-meta">
                H {{ reportResult.data?.total_hadir || 0 }} ·
                I {{ reportResult.data?.total_izin || 0 }} ·
                S {{ reportResult.data?.total_sakit || 0 }} ·
                A {{ reportResult.data?.total_alpha || 0 }}
              </div>
              <div class="table-wrap">
                <table>
                  <thead>
                    <tr><th class="col-no">No</th><th>Karyawan</th><th>Cabang</th><th>H</th><th>I</th><th>S</th><th>A</th><th>Total</th></tr>
                  </thead>
                  <tbody>
                    <tr v-for="(row, idx) in (reportResult.data?.rows || [])" :key="row.employee_id">
                      <td class="col-no">{{ rowNo(idx) }}</td>
                      <td>{{ row.nama }}</td>
                      <td>{{ row.cabang }}</td>
                      <td>{{ row.hadir }}</td>
                      <td>{{ row.izin }}</td>
                      <td>{{ row.sakit }}</td>
                      <td>{{ row.alpha }}</td>
                      <td>{{ row.total }}</td>
                    </tr>
                    <tr v-if="!(reportResult.data?.rows || []).length"><td colspan="8">Tidak ada data.</td></tr>
                  </tbody>
                </table>
              </div>
            </template>

            <template v-else-if="reportForm.type==='gaji'">
              <div class="filter-meta">
                {{ reportResult.data?.periode_label }} ·
                {{ reportResult.data?.jumlah || 0 }} karyawan ·
                Total {{ formatRp(reportResult.data?.total_gaji) }} ·
                Locked {{ reportResult.data?.locked || 0 }} · Draft {{ reportResult.data?.draft || 0 }}
              </div>
              <div class="table-wrap">
                <table>
                  <thead>
                    <tr>
                      <th class="col-no">No</th><th>Cabang</th><th>Karyawan</th><th>Status</th>
                      <th>Hadir</th><th>Gapok</th><th>HP</th><th>Service</th><th>Total</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="(row, idx) in (reportResult.data?.rows || [])" :key="row.id">
                      <td class="col-no">{{ rowNo(idx) }}</td>
                      <td>{{ row.cabang }}</td>
                      <td>{{ row.karyawan }}</td>
                      <td><span class="badge" :class="row.status === 'locked' ? 'badge-approved' : 'badge-pending'">{{ (row.status || '').toUpperCase() }}</span></td>
                      <td>{{ row.hadir }}</td>
                      <td>{{ formatRp(row.gapok) }}</td>
                      <td>{{ formatRp(row.insentif_hp) }}</td>
                      <td>{{ formatRp(row.insentif_service) }}</td>
                      <td style="font-weight:600">{{ formatRp(row.total) }}</td>
                    </tr>
                    <tr v-if="!(reportResult.data?.rows || []).length"><td colspan="9">Tidak ada data gaji.</td></tr>
                  </tbody>
                </table>
              </div>
            </template>

            <template v-else-if="reportForm.type==='upah'">
              <div class="filter-meta">
                {{ reportResult.data?.jumlah || 0 }} job ·
                Gross {{ formatRp(reportResult.data?.total_gross) }} ·
                Upah {{ formatRp(reportResult.data?.total_net) }} ·
                Toko {{ formatRp(reportResult.data?.total_shop) }}
              </div>
              <div class="table-wrap">
                <table>
                  <thead>
                    <tr><th class="col-no">No</th><th>Tanggal</th><th>Cabang</th><th>Teknisi</th><th>Jenis</th><th>Gross</th><th>%</th><th>Net</th></tr>
                  </thead>
                  <tbody>
                    <tr v-for="(row, idx) in (reportResult.data?.rows || [])" :key="row.id">
                      <td class="col-no">{{ rowNo(idx) }}</td>
                      <td>{{ row.tanggal }}</td>
                      <td>{{ row.cabang }}</td>
                      <td>{{ row.teknisi }}</td>
                      <td>{{ row.jenis }}</td>
                      <td>{{ formatRp(row.gross) }}</td>
                      <td>{{ row.pct }}%</td>
                      <td style="font-weight:600">{{ formatRp(row.net) }}</td>
                    </tr>
                    <tr v-if="!(reportResult.data?.rows || []).length"><td colspan="8">Tidak ada data.</td></tr>
                  </tbody>
                </table>
              </div>
            </template>

            <template v-else-if="reportForm.type==='closing'">
              <div class="filter-meta">
                {{ reportResult.data?.periode_label }} ·
                Closing {{ reportResult.data?.total_qty || 0 }} /
                Target {{ reportResult.data?.total_target || 0 }}
                <span v-if="reportResult.data?.pct != null"> ({{ reportResult.data.pct }}%)</span>
              </div>
              <div class="table-wrap">
                <table>
                  <thead>
                    <tr><th class="col-no">No</th><th>Karyawan</th><th>Cabang</th><th>Closing</th><th>Target</th><th>%</th><th>Selisih</th></tr>
                  </thead>
                  <tbody>
                    <tr v-for="(row, idx) in (reportResult.data?.rows || [])" :key="row.employee_id">
                      <td class="col-no">{{ rowNo(idx) }}</td>
                      <td>{{ row.nama }}</td>
                      <td>{{ row.cabang }}</td>
                      <td>{{ row.qty }}</td>
                      <td>{{ row.target }}</td>
                      <td>{{ row.pct != null ? (row.pct + '%') : '—' }}</td>
                      <td :class="row.selisih >= 0 ? 'value-income' : 'value-expense'">{{ row.selisih }}</td>
                    </tr>
                    <tr v-if="!(reportResult.data?.rows || []).length"><td colspan="7">Tidak ada data.</td></tr>
                  </tbody>
                </table>
              </div>
            </template>

            <template v-else-if="reportForm.type==='rekonsiliasi'">
              <div class="filter-meta">
                {{ reportResult.data?.jumlah || 0 }} cek ·
                Ada selisih {{ reportResult.data?.ada_selisih || 0 }} ·
                Total selisih {{ formatRp(reportResult.data?.total_selisih) }}
              </div>
              <div class="table-wrap">
                <table>
                  <thead>
                    <tr><th class="col-no">No</th><th>Tanggal</th><th>Cabang</th><th>Akun</th><th>Sistem</th><th>Fisik</th><th>Selisih</th><th>Oleh</th></tr>
                  </thead>
                  <tbody>
                    <tr v-for="(row, idx) in (reportResult.data?.rows || [])" :key="row.id">
                      <td class="col-no">{{ rowNo(idx) }}</td>
                      <td>{{ row.tanggal }}</td>
                      <td>{{ row.cabang }}</td>
                      <td>{{ row.akun }}</td>
                      <td>{{ formatRp(row.sistem) }}</td>
                      <td>{{ formatRp(row.fisik) }}</td>
                      <td :class="Math.abs(Number(row.selisih||0)) >= 0.01 ? 'value-expense' : 'value-income'">{{ formatRp(row.selisih) }}</td>
                      <td>{{ row.oleh || '—' }}</td>
                    </tr>
                    <tr v-if="!(reportResult.data?.rows || []).length"><td colspan="8">Tidak ada data.</td></tr>
                  </tbody>
                </table>
              </div>
            </template>
          </div>
        </section>

        <section v-if="page==='locks' && isOwner">
          <div class="page-head">
            <div>
              <h2 class="brand">Kunci Periode</h2>
              <p>Kontrol pembukuan bulanan per cabang</p>
            </div>
          </div>
          <div class="grid-2">
            <div class="card">
              <div class="panel-title">Atur Kunci</div>
              <div class="form-grid">
                <div class="field">
                  <label>Cabang</label>
                  <select v-model="lockForm.branch_id">
                    <option disabled value="">Pilih cabang</option>
                    <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                  </select>
                </div>
                <div class="field">
                  <label>Periode</label>
                  <input type="month" v-model="lockForm.period" />
                </div>
                <label class="remember">
                  <input type="checkbox" v-model="lockForm.is_locked" /> Kunci periode
                </label>
                <button class="btn btn-primary" @click="submitPeriodLock">Simpan</button>
              </div>
            </div>
            <div class="card">
              <div class="panel-title">Daftar Periode Terkunci / Terbuka</div>
              <div class="table-wrap">
                <table>
                  <thead>
                    <tr><th class="col-no">No</th><th>Cabang</th><th>Periode</th><th>Status</th><th>Dikunci Oleh</th><th>Aksi</th></tr>
                  </thead>
                  <tbody>
                    <tr v-for="(lock, idx) in periodLocks" :key="lock.id">
                      <td class="col-no">{{ rowNo(idx) }}</td>
                      <td>{{ lock.branch?.name }}</td>
                      <td>{{ lock.period }}</td>
                      <td>
                        <span class="badge" :class="lock.is_locked ? 'badge-rejected' : 'badge-approved'">
                          {{ lock.is_locked ? 'Terkunci' : 'Terbuka' }}
                        </span>
                      </td>
                      <td>{{ lock.locked_by?.name || lock.lockedBy?.name || '—' }}</td>
                      <td>
                        <button v-if="lock.is_locked" class="btn btn-ghost btn-sm" @click="unlockPeriod(lock)">Buka Kunci</button>
                        <button v-else class="btn btn-danger btn-sm" @click="lockForm.branch_id=lock.branch_id; lockForm.period=lock.period; lockForm.is_locked=true; submitPeriodLock()">Kunci Lagi</button>
                      </td>
                    </tr>
                    <tr v-if="!periodLocks.length">
                      <td colspan="6">Belum ada data. Kunci periode terlebih dahulu.</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </section>
        </div>
      </main>
    </div>

    <!-- Edit transaction modal -->
    <div v-if="editTxModal.open" class="modal-backdrop" @click.self="editTxModal.open=false">
      <div class="modal">
        <h3>Ubah Transaksi</h3>
        <div class="form-grid">
          <div class="field">
            <label>Kategori</label>
            <select v-model="editTxModal.category_id">
              <option disabled value="">Pilih kategori</option>
              <option v-for="c in editTxCategories" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
          </div>
          <div class="field">
            <label>Akun</label>
            <select v-model="editTxModal.account_id">
              <option disabled value="">Pilih akun</option>
              <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.name }}</option>
            </select>
          </div>
          <div class="field">
            <label>Nominal</label>
            <input :value="editTxModal.amount" @input="onAmountInput($event, editTxModal)" inputmode="numeric" placeholder="Ketik nominal" />
          </div>
          <div class="field">
            <label>Tanggal</label>
            <input type="date" v-model="editTxModal.transaction_date" />
          </div>
          <div class="field">
            <label>Deskripsi</label>
            <textarea rows="2" v-model="editTxModal.description"></textarea>
          </div>
        </div>
        <div class="modal-actions">
          <button class="btn btn-ghost" @click="editTxModal.open=false">Batal</button>
          <button class="btn btn-primary" @click="submitEditTx">Simpan</button>
        </div>
      </div>
    </div>

    <!-- Payroll detail modal -->
    <div v-if="payrollDetail.open" class="modal-backdrop" @click.self="closePayrollDetail">
      <div class="modal modal-wide">
        <h3>Detail Gaji</h3>
        <div v-if="payrollDetail.loading" class="empty-hint">Memuat…</div>
        <template v-else-if="payrollDetail.data">
          <p class="modal-message">
            <strong>{{ payrollDetail.data.name }}</strong>
            <span class="muted"> · {{ payrollDetail.data.position || '—' }} · {{ payrollDetail.data.branch_name }}</span>
          </p>
          <div class="payroll-detail-grid">
            <div><span>Hadir</span><strong>{{ payrollDetail.data.present_days }} hari</strong></div>
            <div><span>Gapok</span><strong>{{ formatRp(payrollDetail.data.gapok) }}</strong></div>
            <div><span>Qty Closing</span><strong>{{ payrollDetail.data.closing_qty }}</strong></div>
            <div><span>Insentif HP</span><strong>{{ formatRp(payrollDetail.data.insentif_hp) }}</strong></div>
            <div><span>Profit Service</span><strong>{{ formatRp(payrollDetail.data.service_profit) }}</strong></div>
            <div><span>Service 50%</span><strong>{{ formatRp(payrollDetail.data.service_incentive) }}</strong></div>
            <div><span>Insentif ACC</span><strong>{{ formatRp(payrollDetail.data.insentif_acc) }}</strong></div>
            <div><span>Bonus Absen</span><strong>{{ formatRp(payrollDetail.data.bonus_absen) }}</strong></div>
            <div><span>Hutang</span><strong>{{ formatRp(payrollDetail.data.hutang) }}</strong></div>
            <div><span>Pengeluaran</span><strong>{{ formatRp(payrollDetail.data.pengeluaran) }}</strong></div>
            <div class="payroll-detail-total"><span>Total bersih</span><strong>{{ formatRp(payrollDetail.data.total) }}</strong></div>
          </div>
          <div class="panel-title" style="margin-top:14px">Service bulan ini</div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr><th>Tanggal</th><th>Perangkat</th><th>Modal</th><th>Harga</th><th>Profit</th></tr>
              </thead>
              <tbody>
                <tr v-for="s in payrollDetail.services" :key="s.id">
                  <td>{{ formatDate(s.service_date) }}</td>
                  <td>{{ s.brand }} {{ s.device_type }}</td>
                  <td>{{ formatRp(s.cost) }}</td>
                  <td>{{ formatRp(s.price) }}</td>
                  <td>{{ formatRp(s.profit) }}</td>
                </tr>
                <tr v-if="!(payrollDetail.services || []).length">
                  <td colspan="5">Tidak ada catatan servis di periode ini.</td>
                </tr>
              </tbody>
            </table>
          </div>
        </template>
        <div class="modal-actions">
          <button class="btn btn-ghost" type="button" @click="closePayrollDetail">Tutup</button>
        </div>
      </div>
    </div>

    <!-- Closing confirm modal -->
    <div v-if="closingConfirm.open" class="modal-backdrop" @click.self="resolveClosingConfirm(false)">
      <div class="modal modal-confirm">
        <h3>{{ closingConfirm.title }}</h3>
        <p class="modal-message">{{ closingConfirm.message }}</p>
        <div v-if="closingConfirm.detail" class="modal-change">{{ closingConfirm.detail }}</div>
        <div class="modal-actions">
          <button class="btn btn-ghost" type="button" @click="resolveClosingConfirm(false)">Batal</button>
          <button
            class="btn"
            :class="closingConfirm.danger ? 'btn-danger' : 'btn-primary'"
            type="button"
            @click="resolveClosingConfirm(true)"
          >{{ closingConfirm.confirmLabel }}</button>
        </div>
      </div>
    </div>

    <!-- Approve modal -->
    <div v-if="approveModal.open" class="modal-backdrop" @click.self="approveModal.open=false">
      <div class="modal">
        <h3>Konfirmasi Persetujuan</h3>
        <p style="color:#64748B;font-size:.9rem">Masukkan kata sandi Anda untuk menyetujui transfer.</p>
        <div class="field">
          <label>Kata sandi</label>
          <input type="password" v-model="approveModal.password" />
        </div>
        <div class="modal-actions">
          <button class="btn btn-ghost" @click="approveModal.open=false">Batal</button>
          <button class="btn btn-success" @click="confirmApprove">Setujui</button>
        </div>
      </div>
    </div>

    <!-- Reject modal -->
    <div v-if="rejectModal.open" class="modal-backdrop" @click.self="rejectModal.open=false">
      <div class="modal">
        <h3>Tolak Transfer</h3>
        <div class="field">
          <label>Alasan penolakan</label>
          <textarea rows="3" v-model="rejectModal.reason" placeholder="Wajib diisi"></textarea>
        </div>
        <div class="modal-actions">
          <button class="btn btn-ghost" @click="rejectModal.open=false">Batal</button>
          <button class="btn btn-danger" @click="confirmReject">Tolak</button>
        </div>
      </div>
    </div>
  </div>
  `,
}).mount('#app');
