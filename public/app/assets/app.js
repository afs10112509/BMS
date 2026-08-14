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

function formatPctShare(value) {
  const n = Number(value);
  if (!Number.isFinite(n)) return '—';
  if (Math.abs(n - Math.round(n)) < 0.001) return `${Math.round(n)}%`;
  return `${new Intl.NumberFormat('id-ID', { maximumFractionDigits: 2 }).format(n)}%`;
}

function formatInputNumber(raw) {
  if (raw === null || raw === undefined || raw === '') return '';
  // Angka murni dari API/JS (hindari "70000.00" → 7.000.000 karena strip \D)
  if (typeof raw === 'number' && Number.isFinite(raw)) {
    return new Intl.NumberFormat('id-ID').format(Math.round(raw));
  }
  const s = String(raw).trim();
  // Desimal API: 70000 / 70000.00 / 70000,50 (max 2 digit pecahan)
  if (/^-?\d+$/.test(s) || /^-?\d+[.,]\d{1,2}$/.test(s)) {
    return new Intl.NumberFormat('id-ID').format(Math.round(Number(s.replace(',', '.'))));
  }
  // Input berformat id-ID (70.000) — titik = pemisah ribuan
  const digits = s.replace(/\D/g, '');
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

const app = createApp({
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
      operasional: true,
      pendukung: false,
      transfer: true,
      laporan: true,
      karyawan: true,
      konter: true,
      bengkel: true,
      sistem: false,
    });
    const ownerDashExtraOpen = ref(false);

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
    const reportAlurDetail = reactive({
      open: false,
      loading: false,
      pos: '',
      tipe: '',
      meta: null,
      rows: [],
      total: 0,
      jumlah: 0,
    });
    const reportRingkasanDetail = reactive({
      open: false,
      loading: false,
      tanggal: '',
      rows: [],
      pemasukan: 0,
      pengeluaran: 0,
      selisih: 0,
    });
    const reportUpahTechDetail = reactive({
      open: false,
      teknisi: '',
      cabang: '',
      jumlah_job: 0,
      total_gross: 0,
      total_net: 0,
      total_shop: 0,
      tech_share_pct: 0,
      rows: [],
      page: 1,
      per_page: 10,
    });
    const reportUpahTechDetailPageCount = computed(() => {
      const total = (reportUpahTechDetail.rows || []).length;
      const pp = Number(reportUpahTechDetail.per_page) || 10;
      return Math.max(1, Math.ceil(total / pp));
    });
    const reportUpahTechDetailPagedRows = computed(() => {
      const rows = reportUpahTechDetail.rows || [];
      const pp = Number(reportUpahTechDetail.per_page) || 10;
      const page = Math.min(
        Math.max(1, Number(reportUpahTechDetail.page) || 1),
        reportUpahTechDetailPageCount.value,
      );
      const start = (page - 1) * pp;
      return rows.slice(start, start + pp);
    });

    const allReportTypes = [
      { id: 'alur-kas', title: 'Laporan Alur Kas', desc: 'Pemasukan & pengeluaran per pos (agregat transaksi)', group: 'keuangan' },
      { id: 'ringkasan', title: 'Ringkasan Periode', desc: 'Pemasukan, pengeluaran, dan selisih harian', group: 'keuangan' },
      { id: 'kategori', title: 'Per Kategori', desc: 'Rincian transaksi per kategori, total di bawah', group: 'keuangan' },
      { id: 'akun', title: 'Saldo per Akun', desc: 'Posisi saldo Cash, Mandiri, BRI, GoPay', group: 'keuangan' },
      { id: 'transaksi', title: 'Detail Transaksi', desc: 'Daftar lengkap transaksi sesuai filter', group: 'keuangan' },
      { id: 'transfer', title: 'Transfer Antar Cabang', desc: 'Riwayat pengajuan dan status transfer', group: 'keuangan' },
      { id: 'rekonsiliasi', title: 'Rekonsiliasi', desc: 'Riwayat cek fisik vs sistem dan selisih', group: 'keuangan' },
      { id: 'servis', title: 'Catatan Servis', desc: 'Job servis, modal, harga, dan profit', group: 'konter' },
      { id: 'closing', title: 'Closing Harian & Target', desc: 'Closing vs target bulanan per karyawan', group: 'konter' },
      { id: 'keuntungan-pulsa', title: 'Keuntungan Pulsa', desc: 'Saldo terpotong, uang pulsa, dan keuntungan harian', group: 'konter' },
      { id: 'brilink', title: 'Brilink', desc: 'Rekap saldo harian & keuntungan vs kemarin', group: 'keuangan' },
      { id: 'gaji', title: 'Gaji Konter', desc: 'Rekap gaji karyawan konter per bulan', group: 'konter' },
      { id: 'upah', title: 'Upah Bengkel', desc: 'Job dan upah teknisi bengkel', group: 'bengkel' },
      { id: 'bagi-hasil', title: 'Bagi Hasil', desc: 'Laba bersih per cabang & bagian PIC', group: 'sistem' },
      { id: 'absensi', title: 'Absensi', desc: 'Rekap H/I/S/A per karyawan', group: 'karyawan' },
    ];

    const reportTypes = computed(() => allReportTypes.filter((rt) => {
      if (isAdmin.value && !isOwner.value) {
        if (isWorkshopBranch.value) {
          return ['alur-kas', 'brilink', 'upah'].includes(rt.id);
        }
        return !['gaji', 'bagi-hasil', 'upah'].includes(rt.id);
      }
      if (rt.id === 'gaji' || rt.id === 'bagi-hasil') return isOwner.value;
      if (rt.id === 'servis' || rt.id === 'closing' || rt.id === 'keuntungan-pulsa') {
        return isOwner.value || !isWorkshopBranch.value;
      }
      if (rt.id === 'upah') return isOwner.value || isWorkshopBranch.value;
      if (rt.id === 'brilink') return isOwner.value || isAdmin.value;
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
      employee_id: '',
      q: '',
    });
    const reportUpahTechnicians = ref([]);

    const _cfNow = new Date();
    const cashflowFilter = reactive({
      year: _cfNow.getFullYear(),
      month: _cfNow.getMonth() + 1,
      branch_id: '',
      view: 'detail', // detail | ringkas
    });
    const cashflowBoard = ref({ rows: [], meta: null, year: _cfNow.getFullYear(), month: _cfNow.getMonth() + 1 });
    const cashflowExpanded = reactive({});
    const cfEditor = reactive({
      open: false,
      id: null,
      branch_id: '',
      branch_name: '',
      year: _cfNow.getFullYear(),
      month: _cfNow.getMonth() + 1,
      note: '',
      lines: [],
      total_income: 0,
      total_expense: 0,
      net_profit: 0,
      exists: false,
    });
    const cashflowYears = computed(() => {
      const y = new Date().getFullYear();
      return [y - 2, y - 1, y, y + 1];
    });
    const cashflowMonthNames = [
      '', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
      'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
    ];
    const cashflowMonthLabel = computed(() => {
      const m = Number(cashflowFilter.month);
      const y = Number(cashflowFilter.year);
      return `${cashflowMonthNames[m] || m} ${y}`;
    });
    const cfIncomeLines = computed(() => (cfEditor.lines || []).filter((l) => l.type === 'income'));
    const cfExpenseLines = computed(() => (cfEditor.lines || []).filter((l) => l.type === 'expense'));

    const txForm = reactive({
      type: 'income',
      category_id: '',
      account_id: '',
      amount: '',
      transaction_date: today(),
      description: '',
      branch_id: '',
    });
    const txDailyReport = reactive({
      open: false,
      loading: false,
      date: '',
      branch_id: '',
      branch_name: '',
      rows: [],
      total_income: 0,
      total_expense: 0,
      wa_phone: '',
    });

    let txDraftSeq = 1;
    const txDraftRows = ref([]);
    const txDayEdit = reactive({
      active: false,
      date: '',
      branch_id: '',
      originalIds: [],
    });

    function defaultTxAccountId() {
      const cash = accounts.value.find((a) => a.code === 'cash');
      return cash?.id || accounts.value[0]?.id || '';
    }

    function blankTxDraftRow(overrides = {}) {
      return {
        key: txDraftSeq++,
        id: overrides.id || null,
        type: overrides.type || 'income',
        category_id: overrides.category_id || '',
        account_id: overrides.account_id || defaultTxAccountId(),
        amount: overrides.amount || '',
        description: overrides.description || '',
      };
    }

    function clearTxDayEdit() {
      txDayEdit.active = false;
      txDayEdit.date = '';
      txDayEdit.branch_id = '';
      txDayEdit.originalIds = [];
    }

    function ensureTxDraftRows(min = 3) {
      while (txDraftRows.value.length < min) {
        txDraftRows.value.push(blankTxDraftRow());
      }
    }

    function resetTxDraftRows() {
      clearTxDayEdit();
      txDraftRows.value = [
        blankTxDraftRow({ type: 'income' }),
        blankTxDraftRow({ type: 'income' }),
        blankTxDraftRow({ type: 'expense' }),
      ];
    }

    function addTxDraftRow(type = 'income') {
      txDraftRows.value.push(blankTxDraftRow({ type }));
    }

    function removeTxDraftRow(key) {
      if (txDraftRows.value.length <= 1) {
        txDraftRows.value = [blankTxDraftRow()];
        return;
      }
      txDraftRows.value = txDraftRows.value.filter((r) => r.key !== key);
    }

    function onTxDraftTypeChange(row) {
      row.category_id = '';
    }

    function categoriesForTxType(type) {
      return categories.value.filter((c) => c.type === type && c.is_active !== false);
    }

    const txDraftFilled = computed(() =>
      (txDraftRows.value || []).filter((r) => {
        const amount = parseInputNumber(r.amount);
        return r.category_id && r.account_id && amount > 0;
      })
    );

    const txDraftIncome = computed(() =>
      txDraftFilled.value
        .filter((r) => r.type === 'income')
        .reduce((sum, r) => sum + parseInputNumber(r.amount), 0)
    );

    const txDraftExpense = computed(() =>
      txDraftFilled.value
        .filter((r) => r.type === 'expense')
        .reduce((sum, r) => sum + parseInputNumber(r.amount), 0)
    );

    const txDraftNet = computed(() => txDraftIncome.value - txDraftExpense.value);

    const txDraftTotal = computed(() =>
      txDraftFilled.value.reduce((sum, r) => sum + parseInputNumber(r.amount), 0)
    );

    /** Cocokkan uang fisik Penjualan HP hari itu (hanya di form, tidak ke DB). */
    const txFisik = reactive({
      physical: '',
      key: '',
    });

    const txCashAccountId = computed(() => {
      const cash = accounts.value.find((a) => String(a.code || '').toLowerCase() === 'cash');
      return cash?.id ? Number(cash.id) : null;
    });

    function isCashAccountId(accountId) {
      const cashId = txCashAccountId.value;
      return cashId != null && Number(accountId) === Number(cashId);
    }

    function isSalesCategoryName(name) {
      return /penjualan/i.test(String(name || ''));
    }

    function isHpSalesCategoryName(name) {
      return /penjualan\s*hp/i.test(String(name || ''));
    }

    function isPulsaSalesCategoryName(name) {
      return /penjualan\s*pulsa/i.test(String(name || ''));
    }

    function txDraftCategoryName(row) {
      const cat = categories.value.find((c) => Number(c.id) === Number(row.category_id));
      return cat?.name || '';
    }

    const txDraftCashIncome = computed(() => {
      if (!txCashAccountId.value) return 0;
      return txDraftFilled.value
        .filter((r) => r.type === 'income' && isCashAccountId(r.account_id))
        .reduce((sum, r) => sum + parseInputNumber(r.amount), 0);
    });

    function findHpSalesCategoryId() {
      const cat = categories.value.find(
        (c) => c.type === 'income' && isHpSalesCategoryName(c.name) && c.is_active !== false
      );
      return cat?.id || '';
    }

    function listTxDraftHpSalesRows() {
      return (txDraftRows.value || []).filter(
        (r) => r.type === 'income' && isCashAccountId(r.account_id) && isHpSalesCategoryName(txDraftCategoryName(r))
      );
    }

    /** Kunci: uang masuk Penjualan HP ke Cash hari ini. */
    const txDraftHpSalesCash = computed(() => {
      if (!txCashAccountId.value) return 0;
      return listTxDraftHpSalesRows().reduce((sum, r) => sum + parseInputNumber(r.amount), 0);
    });

    const txDraftHpSalesDisplay = computed(() => {
      const n = txDraftHpSalesCash.value;
      return n > 0 ? formatInputNumber(n) : '';
    });

    function onTxHpSalesInput(e) {
      if (periodLocked.value) return;
      const formatted = formatInputNumber(e.target.value);
      const amount = parseInputNumber(formatted);
      const cashId = txCashAccountId.value || defaultTxAccountId();
      const catId = findHpSalesCategoryId();
      if (!catId) {
        toast('Kategori Penjualan HP tidak ditemukan.', 'error');
        return;
      }
      if (!cashId) {
        toast('Akun Cash tidak ditemukan.', 'error');
        return;
      }

      const rows = listTxDraftHpSalesRows();
      if (!rows.length) {
        const empty = txDraftRows.value.find((r) => {
          const hasAmt = parseInputNumber(r.amount) > 0;
          return r.type === 'income' && !r.category_id && !hasAmt;
        });
        if (empty) {
          empty.type = 'income';
          empty.category_id = catId;
          empty.account_id = cashId;
          empty.amount = formatted;
        } else {
          txDraftRows.value.unshift(blankTxDraftRow({
            type: 'income',
            category_id: catId,
            account_id: cashId,
            amount: formatted,
          }));
        }
        return;
      }

      const others = rows.slice(1).reduce((s, r) => s + parseInputNumber(r.amount), 0);
      const firstAmt = Math.max(0, amount - others);
      rows[0].type = 'income';
      rows[0].category_id = catId;
      rows[0].account_id = cashId;
      rows[0].amount = firstAmt > 0 ? formatInputNumber(firstAmt) : (amount === 0 ? '' : formatInputNumber(firstAmt));
      if (amount === 0 && !rows[0].id) {
        rows[0].amount = '';
        rows[0].category_id = '';
      }
    }

    const txDraftPulsaSalesCash = computed(() => {
      if (!txCashAccountId.value) return 0;
      return txDraftFilled.value
        .filter((r) => r.type === 'income' && isCashAccountId(r.account_id) && isPulsaSalesCategoryName(txDraftCategoryName(r)))
        .reduce((sum, r) => sum + parseInputNumber(r.amount), 0);
    });

    const txDraftSalesCash = computed(() => {
      if (!txCashAccountId.value) return 0;
      return txDraftFilled.value
        .filter((r) => r.type === 'income' && isCashAccountId(r.account_id) && isSalesCategoryName(txDraftCategoryName(r)))
        .reduce((sum, r) => sum + parseInputNumber(r.amount), 0);
    });

    const txDraftCashExpense = computed(() => {
      if (!txCashAccountId.value) return 0;
      return txDraftFilled.value
        .filter((r) => r.type === 'expense' && isCashAccountId(r.account_id))
        .reduce((sum, r) => sum + parseInputNumber(r.amount), 0);
    });

    const txDraftCashNet = computed(() => txDraftCashIncome.value - txDraftCashExpense.value);

    const txDraftBankExpense = computed(() =>
      txDraftFilled.value
        .filter((r) => r.type === 'expense' && !isCashAccountId(r.account_id))
        .reduce((sum, r) => sum + parseInputNumber(r.amount), 0)
    );

    const txFisikHasInput = computed(() => String(txFisik.physical || '').trim() !== '');

    /** Selisih = Penjualan HP + Pulsa − uang fisik − total pengeluaran. */
    const txFisikSelisih = computed(() => {
      if (!txFisikHasInput.value) return null;
      return Number(txDraftHpSalesCash.value)
        + Number(txDraftPulsaSalesCash.value || 0)
        - parseInputNumber(txFisik.physical)
        - Number(txDraftExpense.value || 0);
    });

    const txFisikCocok = computed(() => txFisikSelisih.value != null && Math.abs(txFisikSelisih.value) < 1);

    const txFisikStatusLabel = computed(() => {
      if (txFisikSelisih.value == null) return '';
      if (txFisikCocok.value) return 'Cocok';
      return txFisikSelisih.value > 0 ? 'Lebih' : 'Kurang';
    });

    const txFisikStatusAmount = computed(() => {
      if (txFisikSelisih.value == null) return 0;
      return Math.abs(Number(txFisikSelisih.value));
    });

    function txFisikStorageKey(date, branchId) {
      return `bms_tx_fisik_hp_${branchId || 'x'}_${date || ''}`;
    }

    function persistTxFisikInput() {
      if (!txFisik.key || typeof sessionStorage === 'undefined') return;
      try {
        if (txFisikHasInput.value) sessionStorage.setItem(txFisik.key, String(txFisik.physical));
        else sessionStorage.removeItem(txFisik.key);
      } catch (_) {}
    }

    function onTxFisikInput(e) {
      txFisik.physical = formatInputNumber(e.target.value);
      persistTxFisikInput();
    }

    function syncTxFisikStorageKey() {
      const date = String(txForm.transaction_date || '').slice(0, 10);
      const branchId = isOwner.value
        ? (txForm.branch_id || '')
        : (user.value?.branch_id || '');
      if (!date || (isOwner.value && !branchId)) {
        txFisik.key = '';
        return;
      }
      const key = txFisikStorageKey(date, branchId);
      if (txFisik.key === key) return;
      txFisik.key = key;
      try {
        txFisik.physical = (typeof sessionStorage !== 'undefined' && sessionStorage.getItem(key)) || '';
      } catch (_) {
        txFisik.physical = '';
      }
    }

    function scheduleRefreshTxFisikSystemBase() {
      syncTxFisikStorageKey();
    }

    const txFilter = reactive({
      type: '',
      category_id: '',
      date_from: monthStart(),
      date_to: today(),
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
      reconciliation_id: null,
    });
    const adjustmentReconAlerts = ref([]);
    const adjustmentReconLoading = ref(false);

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
    const kelolaCategoryQuery = ref('');
    const kelolaCategoryPage = ref(1);
    const kelolaCategoryPerPage = 10;

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
    const canAccessWorkshopUpahReport = computed(() => canAccessWorkshopWages.value);
    const workshopBranches = computed(() =>
      branches.value.filter((b) => {
        if (typeof b.allows_service === 'boolean') return !b.allows_service;
        return String(b.type || '').toLowerCase() === 'bengkel';
      })
    );
    const reportBranches = computed(() => {
      if (reportForm.type === 'gaji' || reportForm.type === 'closing' || reportForm.type === 'servis') {
        return konterBranches.value;
      }
      if (reportForm.type === 'upah') {
        return workshopBranches.value;
      }
      return branches.value;
    });

    const wwTab = ref('daily');
    const wwDailyDate = ref(today());
    const wwJobs = ref([]);
    const wwTechnicians = ref([]);
    const wwJobTypes = ref([]);
    const wwJobTypeCatalog = ref([]);
    const wwWeeks = ref([]);
    const wwWeekDetail = ref(null);
    const wwMeta = ref(null);
    let wwDraftSeq = 1;
    const wwDraftRows = ref([]);
    const wwJobTypeForm = reactive({
      id: null,
      name: '',
      default_amount: '',
      status: 'active',
      sort_order: 0,
    });
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

    function defaultWwJobTypeName() {
      return wwJobTypes.value[0]?.name || 'ONGKER';
    }

    function blankWwDraftRow(overrides = {}) {
      const jobType = overrides.job_type || defaultWwJobTypeName();
      const match = wwJobTypes.value.find((t) => t.name === jobType);
      let amount = overrides.amount || '';
      if (!amount && match?.default_amount != null) {
        amount = formatInputNumber(match.default_amount);
      }
      return {
        key: wwDraftSeq++,
        job_type: jobType,
        // Default kosong agar teknisi wajib dipilih sengaja (hindari salah input)
        employee_id: overrides.employee_id || '',
        amount,
        note: overrides.note || '',
      };
    }

    function applyWwJobTypeDefault(row) {
      const match = wwJobTypes.value.find((t) => t.name === row.job_type);
      if (match?.default_amount != null) {
        row.amount = formatInputNumber(match.default_amount);
      }
    }

    function ensureWwDraftRows(min = 3) {
      while (wwDraftRows.value.length < min) {
        wwDraftRows.value.push(blankWwDraftRow());
      }
    }

    function resetWwDraftRows() {
      wwDraftRows.value = [
        blankWwDraftRow(),
        blankWwDraftRow(),
        blankWwDraftRow(),
      ];
    }

    function addWwDraftRow(jobType) {
      wwDraftRows.value.push(
        blankWwDraftRow(jobType ? { job_type: jobType } : {})
      );
    }

    function removeWwDraftRow(key) {
      if (wwDraftRows.value.length <= 1) {
        wwDraftRows.value = [blankWwDraftRow()];
        return;
      }
      wwDraftRows.value = wwDraftRows.value.filter((r) => r.key !== key);
    }

    const wwDraftFilled = computed(() =>
      (wwDraftRows.value || []).filter((r) => {
        const amount = parseInputNumber(r.amount);
        return r.employee_id && String(r.job_type || '').trim() && amount > 0;
      })
    );

    const wwDraftTotal = computed(() =>
      wwDraftFilled.value.reduce((sum, r) => sum + parseInputNumber(r.amount), 0)
    );

    const wwJobsDayTotal = computed(() =>
      (wwJobs.value || []).reduce((sum, j) => sum + Number(j.amount || 0), 0)
    );

    function formatWwDateLabel(ymd) {
      if (!ymd) return '—';
      const d = new Date(`${ymd}T00:00:00`);
      if (Number.isNaN(d.getTime())) return ymd;
      return new Intl.DateTimeFormat('id-ID', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
      }).format(d);
    }

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

    const _psDefault = (() => {
      const d = new Date();
      return { month: d.getMonth() + 1, year: d.getFullYear() };
    })();
    const psFilter = reactive({
      branch_id: '',
      month: _psDefault.month,
      year: _psDefault.year,
    });
    const psBoard = ref({ rows: [], meta: null, year: _psDefault.year, month: _psDefault.month });
    const psEditor = reactive({
      open: false,
      id: null,
      branch_id: '',
      branch_name: '',
      branch_type: '',
      year: _psDefault.year,
      month: _psDefault.month,
      status: 'draft',
      pic_name: '',
      pic_share_pct: 50,
      note: '',
      lines: [],
      total_income: 0,
      total_expense: 0,
      net_profit: 0,
      pic_amount: 0,
      exists: false,
    });
    const psYears = computed(() => {
      const y = new Date().getFullYear();
      return [y - 1, y, y + 1];
    });
    const psMonthLabel = computed(() => {
      const m = Number(psFilter.month);
      const y = Number(psFilter.year);
      try {
        return new Intl.DateTimeFormat('id-ID', { month: 'long', year: 'numeric' })
          .format(new Date(y, m - 1, 1));
      } catch (_) {
        return `${m}/${y}`;
      }
    });
    const canAccessProfitShares = computed(() => isOwner.value);
    // Buat Laporan: Owner + Admin (jenis disaring di reportTypes).
    // Workbook Alur Kas (input): Owner only.
    const canAccessReports = computed(() => isOwner.value || isAdmin.value);
    const canAccessCashflow = computed(() => isOwner.value);
    const canAccessTodayOps = computed(() => isAdmin.value);
    const psIncomeLines = computed(() => (psEditor.lines || []).filter((l) => l.type === 'income'));
    const psExpenseLines = computed(() => (psEditor.lines || []).filter((l) => l.type === 'expense'));

    const serviceRecords = ref([]);
    const serviceSummary = ref({ jumlah: 0, total_modal: 0, total_harga: 0, total_profit: 0 });
    const serviceMeta = ref({ total: 0, current_page: 1, last_page: 1, per_page: 20 });
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

    let svcDraftSeq = 1;
    const svcDraftRows = ref([]);

    function blankSvcDraftRow(overrides = {}) {
      return {
        key: svcDraftSeq++,
        employee_id: overrides.employee_id || '',
        brand: overrides.brand || '',
        device_type: overrides.device_type || '',
        damage: overrides.damage || '',
        cost: overrides.cost || '',
        price: overrides.price || '',
        notes: overrides.notes || '',
      };
    }

    function ensureSvcDraftRows(min = 3) {
      while (svcDraftRows.value.length < min) {
        svcDraftRows.value.push(blankSvcDraftRow());
      }
    }

    function resetSvcDraftRows() {
      svcDraftRows.value = [
        blankSvcDraftRow(),
        blankSvcDraftRow(),
        blankSvcDraftRow(),
      ];
    }

    function addSvcDraftRow() {
      svcDraftRows.value.push(blankSvcDraftRow());
    }

    function removeSvcDraftRow(key) {
      if (svcDraftRows.value.length <= 1) {
        svcDraftRows.value = [blankSvcDraftRow()];
        return;
      }
      svcDraftRows.value = svcDraftRows.value.filter((r) => r.key !== key);
    }

    function svcDraftProfit(row) {
      return parseInputNumber(row.price) - parseInputNumber(row.cost);
    }

    const svcDraftFilled = computed(() =>
      (svcDraftRows.value || []).filter((r) => {
        return r.employee_id
          && String(r.brand || '').trim()
          && String(r.device_type || '').trim()
          && String(r.damage || '').trim();
      })
    );

    const svcDraftTotalProfit = computed(() =>
      svcDraftFilled.value.reduce((sum, r) => sum + svcDraftProfit(r), 0)
    );

    const serviceFilter = reactive({
      branch_id: '',
      employee_id: '',
      date_from: monthStart(),
      date_to: today(),
      q: '',
    });

    // Keuntungan Pulsa (konter) — modul mandiri
    let pulsaExpenseSeq = 1;
    const pulsaTab = ref('daily');
    const pulsaDate = ref(today());
    const pulsaBranchId = ref('');
    const pulsaProviders = ref([]);
    const pulsaHistory = ref([]);
    const pulsaShowProviders = ref(false);
    const pulsaProviderForm = reactive({ name: '' });
    const pulsaForm = reactive({
      id: null,
      cash_on_hand: '',
      note: '',
      is_new: true,
    });
    const pulsaBalances = ref([]);
    const pulsaExpenses = ref([]);

    function blankPulsaExpense(overrides = {}) {
      return {
        key: pulsaExpenseSeq++,
        name: overrides.name || '',
        amount: overrides.amount || '',
      };
    }

    function ensurePulsaExpenseRows(min = 3) {
      while (pulsaExpenses.value.length < min) {
        pulsaExpenses.value.push(blankPulsaExpense());
      }
    }

    function pulsaUsedOf(row) {
      const opening = parseInputNumber(row.opening_balance);
      const topup = parseInputNumber(row.topup_amount);
      const closing = parseInputNumber(row.closing_balance);
      return opening + topup - closing;
    }

    const pulsaTotalUsed = computed(() =>
      (pulsaBalances.value || []).reduce((s, r) => s + pulsaUsedOf(r), 0)
    );

    const pulsaTotalExpense = computed(() =>
      (pulsaExpenses.value || []).reduce((s, r) => {
        const name = String(r.name || '').trim();
        const amount = parseInputNumber(r.amount);
        return name && amount > 0 ? s + amount : s;
      }, 0)
    );

    const pulsaTotalCash = computed(() =>
      parseInputNumber(pulsaForm.cash_on_hand) + pulsaTotalExpense.value
    );

    const pulsaProfit = computed(() => pulsaTotalCash.value - pulsaTotalUsed.value);

    // Brilink harian — semua cabang, modul mandiri (belum ke kas)
    let brilinkLineSeq = 1;
    const brilinkDate = ref(today());
    const brilinkBranchId = ref('');
    const brilinkHistory = ref([]);
    const brilinkForm = reactive({
      id: null,
      previous_total: '',
      note: '',
      is_new: true,
    });
    const brilinkLines = ref([]);

    function blankBrilinkLine(overrides = {}) {
      return {
        key: brilinkLineSeq++,
        name: overrides.name || '',
        amount: overrides.amount || '',
      };
    }

    function ensureBrilinkLines(min = 1) {
      while (brilinkLines.value.length < min) {
        brilinkLines.value.push(blankBrilinkLine());
      }
    }

    const brilinkTotal = computed(() =>
      (brilinkLines.value || []).reduce((s, r) => {
        const name = String(r.name || '').trim();
        return name ? s + parseInputNumber(r.amount) : s;
      }, 0)
    );

    const brilinkProfit = computed(() =>
      brilinkTotal.value - parseInputNumber(brilinkForm.previous_total)
    );

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

    const dbBackupList = ref([]);
    const dbBackupMeta = reactive({
      keep: 10,
      note: '',
      confirm_phrase: 'PULIHKAN',
      upload_max_kb: 20480,
      schedule: {
        enabled: true,
        time: '02:00',
        timezone: 'Asia/Jayapura',
        last_run_at: null,
        last_status: null,
        last_filename: null,
        last_error: null,
        next_run_at: null,
      },
    });
    const dbBackupForm = reactive({
      password: '',
      creating: false,
      uploading: false,
      uploadFile: null,
      scheduleSaving: false,
      scheduleEnabled: true,
      scheduleTime: '02:00',
    });
    const dbRestoreModal = reactive({
      open: false,
      filename: '',
      password: '',
      confirm_phrase: '',
      restoring: false,
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
    // Alias seragam untuk konfirmasi destruktif / kunci di seluruh SPA.
    const askConfirm = askClosingConfirm;

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
    const isEmployeeRole = computed(() => user.value?.role === 'employee');
    const isPicEmployee = computed(() => {
      const positions = user.value?.employee?.positions;
      return isEmployeeRole.value && Array.isArray(positions) && positions.includes('pic');
    });
    const isPicWorkshop = computed(() => {
      if (!isPicEmployee.value) return false;
      const b = user.value?.employee?.branch;
      if (!b) return false;
      if (typeof b.allows_service === 'boolean') return !b.allows_service;
      return false;
    });
    const isPicCounter = computed(() => {
      if (!isPicEmployee.value) return false;
      const b = user.value?.employee?.branch;
      if (!b) return false;
      return b.allows_service === true;
    });

    // Mutasi: Admin/PIC konter. Owner hanya pantau (akses tanpa canInput).
    const canInputPulsa = computed(() => {
      if (isOwner.value) return false;
      if (isAdmin.value && !isWorkshopBranch.value) return true;
      return isPicCounter.value;
    });
    const canAccessPulsa = computed(() => isOwner.value || canInputPulsa.value);

    const canInputBrilink = computed(() => {
      if (isOwner.value) return false;
      if (isAdmin.value) return true;
      return isPicEmployee.value;
    });
    const canAccessBrilink = computed(() => isOwner.value || canInputBrilink.value);

    const empToday = ref(null);
    const empHistory = ref([]);
    const empHistoryFilter = reactive({
      year: new Date().getFullYear(),
      month: new Date().getMonth() + 1,
    });
    const empPhotoPreview = ref('');
    const empLeaveForm = reactive({ status: 'leave', note: '' });
    const attSettingsForm = reactive({
      branch_id: '',
      check_in_start: '07:00',
      check_in_end: '09:00',
      check_out_start: '16:00',
      check_out_end: '20:00',
    });
    const attReviewRows = ref([]);
    const empAccountModal = reactive({
      open: false,
      employee_id: null,
      employee_name: '',
      email: '',
      password: '',
    });

    const isAdmin = computed(() => user.value?.role === 'admin');
    const roleLabel = computed(() => {
      if (isOwner.value) return 'Pemilik';
      if (isEmployeeRole.value) {
        const branch = user.value?.employee?.branch?.name || user.value?.branch?.name || '';
        const prefix = isPicEmployee.value ? 'PIC' : 'Karyawan';
        return branch ? `${prefix} · ${branch}` : prefix;
      }
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

    const kelolaCategoriesFiltered = computed(() => {
      const q = String(kelolaCategoryQuery.value || '').trim().toLowerCase();
      let rows = categories.value.slice();
      if (q) {
        rows = rows.filter((c) => {
          const name = String(c.name || '').toLowerCase();
          const scope = String(categoryScopeLabel(c) || '').toLowerCase();
          const type = c.type === 'income' ? 'pemasukan' : 'pengeluaran';
          const status = c.is_active !== false ? 'aktif' : 'nonaktif';
          return name.includes(q) || scope.includes(q) || type.includes(q) || status.includes(q);
        });
      }
      return rows;
    });
    const kelolaCategoryPageCount = computed(() =>
      Math.max(1, Math.ceil(kelolaCategoriesFiltered.value.length / kelolaCategoryPerPage))
    );
    const kelolaCategoriesPaged = computed(() => {
      const page = Math.min(Math.max(1, kelolaCategoryPage.value), kelolaCategoryPageCount.value);
      const start = (page - 1) * kelolaCategoryPerPage;
      return kelolaCategoriesFiltered.value.slice(start, start + kelolaCategoryPerPage);
    });
    watch(kelolaCategoryQuery, () => { kelolaCategoryPage.value = 1; });
    watch(kelolaCategoryPageCount, (n) => {
      if (kelolaCategoryPage.value > n) kelolaCategoryPage.value = n;
    });

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

    const utamaPages = ['dashboard', 'transactions', 'brilink', 'recon'];
    const operasionalPages = ['today-ops', 'attendance', 'brilink', 'services', 'closings', 'pulsa-profit', 'transactions', 'workshop-wages'];
    const pendukungPages = ['recon', 'branch-accounts', 'branch-categories', 'internal-transfer', 'transfers'];
    const transferPages = ['internal-transfer', 'transfers'];
    const laporanPages = ['reports', 'cashflow'];
    const karyawanPages = ['employees', 'attendance'];
    const konterPages = ['closings', 'payroll', 'services', 'pulsa-profit'];
    const bengkelPages = ['workshop-wages'];
    const sistemPages = ['adjustments', 'locks', 'kelola', 'profit-shares', 'db-backup'];

    const canAccessKonterMenu = computed(() => canAccessClosings.value || canAccessPayroll.value || canAccessPulsa.value);

    function toggleNavGroup(key) {
      navGroups[key] = !navGroups[key];
    }

    function syncNavGroups(nextPage = page.value) {
      if (utamaPages.includes(nextPage)) navGroups.utama = true;
      if (operasionalPages.includes(nextPage)) navGroups.operasional = true;
      if (pendukungPages.includes(nextPage)) navGroups.pendukung = true;
      if (transferPages.includes(nextPage)) navGroups.transfer = true;
      if (laporanPages.includes(nextPage)) navGroups.laporan = true;
      if (karyawanPages.includes(nextPage)) navGroups.karyawan = true;
      if (konterPages.includes(nextPage)) navGroups.konter = true;
      if (bengkelPages.includes(nextPage)) navGroups.bengkel = true;
      if (sistemPages.includes(nextPage)) navGroups.sistem = true;
    }

    async function openReportFromDash(type, branchId = '') {
      if (!canAccessReports.value) return;
      reportForm.type = type;
      if (isOwner.value) {
        reportForm.branch_id = branchId ? String(branchId) : (ownerDashBranchId.value ? String(ownerDashBranchId.value) : '');
        const y = Number(ownerDashYear.value);
        const m = Number(ownerDashMonth.value);
        if (y && m) {
          const last = new Date(y, m, 0).getDate();
          reportForm.date_from = `${y}-${String(m).padStart(2, '0')}-01`;
          reportForm.date_to = `${y}-${String(m).padStart(2, '0')}-${String(last).padStart(2, '0')}`;
        }
      } else {
        reportForm.branch_id = '';
      }
      reportResult.value = null;
      await go('reports');
      await loadReport();
    }

    async function toggleOwnerDashExtra() {
      ownerDashExtraOpen.value = !ownerDashExtraOpen.value;
      if (ownerDashExtraOpen.value) {
        await nextTick();
        renderOwnerCharts();
      }
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

    async function hardReloadApp() {
      try {
        if ('caches' in window) {
          const keys = await caches.keys();
          await Promise.all(keys.map((k) => caches.delete(k)));
        }
      } catch (_) {
        // abaikan kegagalan bersihkan cache
      }
      try {
        if (navigator.serviceWorker?.getRegistrations) {
          const regs = await navigator.serviceWorker.getRegistrations();
          await Promise.all(regs.map((r) => r.update().catch(() => {})));
        }
      } catch (_) {}
      try {
        sessionStorage.setItem('bms_hard_reload', String(Date.now()));
      } catch (_) {}
      // Muat ulang paksa dari server (bukan hanya state Vue).
      const url = new URL(window.location.href);
      url.searchParams.set('_r', String(Date.now()));
      window.location.replace(url.toString());
    }

    const pwaInstallPrompt = ref(null);
    const pwaCanInstall = ref(false);
    const pwaIsStandalone = computed(() => {
      try {
        return window.matchMedia('(display-mode: standalone)').matches
          || window.navigator.standalone === true
          || document.referrer.includes('android-app://');
      } catch (_) {
        return false;
      }
    });
    const showPwaInstall = computed(() => !pwaIsStandalone.value && (pwaCanInstall.value || isTouchLikePwaHint()));

    function isTouchLikePwaHint() {
      try {
        return window.matchMedia('(max-width: 920px)').matches || ('ontouchstart' in window);
      } catch (_) {
        return false;
      }
    }

    function bindPwaInstallEvents() {
      window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        pwaInstallPrompt.value = e;
        pwaCanInstall.value = true;
      });
      window.addEventListener('appinstalled', () => {
        pwaInstallPrompt.value = null;
        pwaCanInstall.value = false;
        toast('BMS berhasil dipasang ke layar utama.', 'success');
      });
    }

    async function installPwaApp() {
      const promptEvent = pwaInstallPrompt.value;
      if (promptEvent && typeof promptEvent.prompt === 'function') {
        promptEvent.prompt();
        try {
          const choice = await promptEvent.userChoice;
          pwaInstallPrompt.value = null;
          pwaCanInstall.value = false;
          if (choice?.outcome === 'accepted') {
            toast('BMS dipasang ke layar utama.', 'success');
          }
        } catch (_) {
          toast('Pemasangan dibatalkan atau gagal.', 'info');
        }
        return;
      }
      const ua = navigator.userAgent || '';
      const isIOS = /iPad|iPhone|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
      if (isIOS) {
        toast('Di iPhone/iPad: ketuk Bagikan → Tambah ke Layar Utama.', 'info');
      } else {
        toast('Di Android: menu browser ⋮ → Instal aplikasi / Tambahkan ke layar utama.', 'info');
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
      txDraftRows.value.forEach((r) => {
        if (!r.account_id || !ids.has(Number(r.account_id))) r.account_id = defaultId;
      });
      ensureTxDraftRows(3);
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
      const ok = await askConfirm({ title: 'Hapus Akun', message: `Hapus akun "${a.name}"?`, confirmLabel: 'Hapus', danger: true });
      if (!ok) return;
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
      // Ringkasan per tanggal: ambil halaman lebih besar agar grup hari jarang terpotong.
      params.set('per_page', '200');
      params.set('page', String(pageNum));
      if (isOwner.value && txForm.branch_id) params.set('branch_id', String(txForm.branch_id));
      if (txFilter.type) params.set('type', txFilter.type);
      if (txFilter.category_id) params.set('category_id', String(txFilter.category_id));
      if (txFilter.date_from) params.set('date_from', txFilter.date_from);
      if (txFilter.date_to) params.set('date_to', txFilter.date_to);
      if (txFilter.q.trim()) params.set('q', txFilter.q.trim());
      return `/transactions?${params.toString()}`;
    }

    async function fetchTxDayRows(date, branchId) {
      const params = new URLSearchParams();
      params.set('date_from', date);
      params.set('date_to', date);
      params.set('per_page', '200');
      if (isOwner.value && branchId) params.set('branch_id', String(branchId));
      const data = await api(`/transactions?${params.toString()}`);
      const pageData = data.data;
      return pageData?.data || (Array.isArray(pageData) ? pageData : []);
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
      txFilter.date_from = monthStart();
      txFilter.date_to = today();
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

    /** Kelompokkan daftar transaksi halaman aktif per tanggal (+cabang untuk Owner). */
    const transactionGroups = computed(() => {
      const map = new Map();
      (transactions.value || []).forEach((t) => {
        const date = String(t.transaction_date || '').slice(0, 10);
        if (!date) return;
        const branchId = Number(t.branch_id || t.branch?.id || 0) || '';
        const branchName = t.branch?.name || '—';
        const key = isOwner.value ? `${date}__${branchId}` : date;
        if (!map.has(key)) {
          map.set(key, {
            key,
            date,
            branch_id: branchId,
            branch_name: branchName,
            rows: [],
            income: 0,
            expense: 0,
          });
        }
        const g = map.get(key);
        g.rows.push(t);
        const amount = Number(t.amount || 0);
        if (t.category?.type === 'income') g.income += amount;
        else g.expense += amount;
      });
      return Array.from(map.values()).map((g) => ({
        ...g,
        count: g.rows.length,
        net: g.income - g.expense,
      }));
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
      if (reportForm.type === 'upah' && reportForm.employee_id) {
        params.set('employee_id', String(reportForm.employee_id));
      }
      if (reportForm.q.trim()) params.set('q', reportForm.q.trim());
      const qs = params.toString();
      return qs ? `?${qs}` : '';
    }

    async function loadReportUpahTechnicians() {
      if (reportForm.type !== 'upah') {
        reportUpahTechnicians.value = [];
        return;
      }
      try {
        const params = new URLSearchParams();
        params.set('status', 'active');
        if (isOwner.value && reportForm.branch_id) {
          params.set('branch_id', String(reportForm.branch_id));
        }
        const data = await api(`/employees?${params.toString()}`);
        const workshopIds = new Set(workshopBranches.value.map((b) => Number(b.id)));
        const rows = (data.data || [])
          .filter((e) => workshopIds.has(Number(e.branch_id)))
          .filter((e) => !(Array.isArray(e.positions) && e.positions.includes('owner')))
          .slice()
          .sort((a, b) => String(a.name || '').localeCompare(String(b.name || ''), 'id'));
        reportUpahTechnicians.value = rows;
        if (
          reportForm.employee_id &&
          !rows.some((e) => Number(e.id) === Number(reportForm.employee_id))
        ) {
          reportForm.employee_id = '';
        }
      } catch (_) {
        reportUpahTechnicians.value = [];
      }
    }

    async function loadReport() {
      loading.value = true;
      try {
        if (reportForm.type === 'upah') await loadReportUpahTechnicians();
        const data = await api(`/reports/${reportForm.type}${buildReportQuery()}`);
        reportResult.value = data;
      } catch (_) {
        reportResult.value = null;
      } finally {
        loading.value = false;
      }
    }

    async function selectReportType(id) {
      reportForm.type = id;
      reportResult.value = null;
      if (id !== 'upah') {
        reportForm.employee_id = '';
        reportUpahTechnicians.value = [];
      } else {
        await loadReportUpahTechnicians();
      }
    }

    async function onReportUpahBranchChange() {
      if (reportForm.type !== 'upah') return;
      reportForm.employee_id = '';
      await loadReportUpahTechnicians();
    }

    async function openReportAlurDetail(row) {
      if (!row?.category_id) return;
      reportAlurDetail.open = true;
      reportAlurDetail.loading = true;
      reportAlurDetail.pos = row.nama || '';
      reportAlurDetail.tipe = row.type || (reportForm.type === 'alur-kas' ? '' : '');
      reportAlurDetail.meta = null;
      reportAlurDetail.rows = [];
      reportAlurDetail.total = 0;
      reportAlurDetail.jumlah = 0;
      try {
        const params = new URLSearchParams();
        params.set('category_id', String(row.category_id));
        params.set('date_from', reportForm.date_from);
        params.set('date_to', reportForm.date_to);
        if (isOwner.value && reportForm.branch_id) {
          params.set('branch_id', String(reportForm.branch_id));
        }
        const data = await api(`/cashflow/category-transactions?${params.toString()}`);
        reportAlurDetail.meta = data.meta || null;
        reportAlurDetail.pos = data.meta?.pos || row.nama || '';
        reportAlurDetail.tipe = data.meta?.tipe || '';
        reportAlurDetail.rows = data.data?.rows || [];
        reportAlurDetail.total = Number(data.data?.total || 0);
        reportAlurDetail.jumlah = Number(data.data?.jumlah || 0);
      } catch (_) {
        reportAlurDetail.open = false;
      } finally {
        reportAlurDetail.loading = false;
      }
    }

    function closeReportAlurDetail() {
      reportAlurDetail.open = false;
    }

    async function openReportRingkasanDetail(row) {
      const day = String(row?.tanggal || '').slice(0, 10);
      if (!day) return;
      reportRingkasanDetail.open = true;
      reportRingkasanDetail.loading = true;
      reportRingkasanDetail.tanggal = day;
      reportRingkasanDetail.rows = [];
      reportRingkasanDetail.pemasukan = Number(row?.pemasukan || 0);
      reportRingkasanDetail.pengeluaran = Number(row?.pengeluaran || 0);
      reportRingkasanDetail.selisih = Number(row?.selisih || 0);
      try {
        const params = new URLSearchParams();
        params.set('date_from', day);
        params.set('date_to', day);
        params.set('per_page', '200');
        if (isOwner.value && reportForm.branch_id) {
          params.set('branch_id', String(reportForm.branch_id));
        }
        if (reportForm.type_filter) {
          params.set('type', reportForm.type_filter);
        }
        const data = await api(`/transactions?${params.toString()}`);
        const list = data.data?.data || data.data || [];
        reportRingkasanDetail.rows = Array.isArray(list) ? list : [];
      } catch (_) {
        reportRingkasanDetail.open = false;
        toast('Gagal memuat detail transaksi harian.', 'error');
      } finally {
        reportRingkasanDetail.loading = false;
      }
    }

    function closeReportRingkasanDetail() {
      reportRingkasanDetail.open = false;
    }

    function openReportUpahTechDetail(row) {
      const empId = Number(row?.employee_id || 0);
      if (!empId) return;
      const all = reportResult.value?.data?.rows || [];
      const rows = all.filter((r) => Number(r.employee_id) === empId);
      reportUpahTechDetail.open = true;
      reportUpahTechDetail.teknisi = row.teknisi || '—';
      reportUpahTechDetail.cabang = row.cabang || '—';
      reportUpahTechDetail.jumlah_job = Number(row.jumlah_job || rows.length || 0);
      reportUpahTechDetail.total_gross = Number(row.total_gross || 0);
      reportUpahTechDetail.total_net = Number(row.total_net || 0);
      reportUpahTechDetail.total_shop = Number(row.total_shop || 0);
      reportUpahTechDetail.tech_share_pct = Number(row.tech_share_pct || 0);
      reportUpahTechDetail.rows = rows;
      reportUpahTechDetail.page = 1;
    }

    function closeReportUpahTechDetail() {
      reportUpahTechDetail.open = false;
      reportUpahTechDetail.page = 1;
    }

    function setReportUpahTechDetailPage(next) {
      const last = reportUpahTechDetailPageCount.value;
      const page = Math.min(Math.max(1, Number(next) || 1), last);
      reportUpahTechDetail.page = page;
    }

    function cashflowPeriodBounds() {
      const y = Number(cashflowFilter.year);
      const m = Number(cashflowFilter.month);
      const from = `${y}-${String(m).padStart(2, '0')}-01`;
      const last = new Date(y, m, 0).getDate();
      const to = `${y}-${String(m).padStart(2, '0')}-${String(last).padStart(2, '0')}`;
      return { from, to };
    }

    function isCashflowBranchExpanded(branchId) {
      if (cashflowFilter.view === 'detail') return true;
      return !!cashflowExpanded[branchId];
    }

    function toggleCashflowBranch(branchId) {
      if (cashflowFilter.view === 'detail') return;
      cashflowExpanded[branchId] = !cashflowExpanded[branchId];
    }

    function amountClass(n) {
      return Number(n || 0) < 0 ? 'value-expense' : 'value-income';
    }

    function reportTechLabel(e) {
      if (!e) return '';
      const branch = e.branch && e.branch.name ? (' · ' + e.branch.name) : '';
      return String(e.name || '') + branch;
    }

    function recalcCfEditor() {
      let income = 0;
      let expense = 0;
      for (const line of cfEditor.lines || []) {
        const amount = Number(line.amount || 0);
        if (line.type === 'income') income += amount;
        else if (line.type === 'expense') expense += amount;
      }
      cfEditor.total_income = income;
      cfEditor.total_expense = expense;
      cfEditor.net_profit = income - expense;
    }

    function fillCfEditor(row) {
      if (!row) return;
      cfEditor.open = true;
      cfEditor.id = row.id || null;
      cfEditor.branch_id = row.branch_id;
      cfEditor.branch_name = row.branch_name || '';
      cfEditor.year = row.year;
      cfEditor.month = row.month;
      cfEditor.note = row.note || '';
      cfEditor.exists = !!row.exists || !!row.id;
      cfEditor.lines = (row.lines || []).map((l, idx) => ({
        key: `${l.type}-${idx}-${l.name || ''}-${l.id || Math.random()}`,
        type: l.type,
        name: l.name || '',
        amount: Number(l.amount || 0),
        category_id: l.category_id || null,
        source: l.source || 'manual',
        sort_order: Number(l.sort_order ?? idx),
      }));
      recalcCfEditor();
    }

    function closeCfEditor() {
      cfEditor.open = false;
    }

    async function loadCashflowBoard() {
      loading.value = true;
      try {
        const params = new URLSearchParams();
        params.set('year', String(cashflowFilter.year));
        params.set('month', String(cashflowFilter.month));
        if (isOwner.value && cashflowFilter.branch_id) {
          params.set('branch_id', String(cashflowFilter.branch_id));
        }
        const data = await api(`/cashflow/workbook?${params.toString()}`);
        cashflowBoard.value = data.data || { rows: [], meta: null };
        Object.keys(cashflowExpanded).forEach((k) => { delete cashflowExpanded[k]; });
        if (cfEditor.open && cfEditor.branch_id) {
          const found = (cashflowBoard.value.rows || []).find((r) => Number(r.branch_id) === Number(cfEditor.branch_id));
          if (found) fillCfEditor(found);
        }
      } catch (_) {
        cashflowBoard.value = { rows: [], meta: null };
        toast('Gagal memuat alur kas.', 'error');
      } finally {
        loading.value = false;
      }
    }

    function onCashflowFilterChange() {
      closeCfEditor();
      loadCashflowBoard();
    }

    function openCfEditor(row) {
      fillCfEditor(row);
    }

    function addCfLine(type) {
      cfEditor.lines.push({
        key: `${type}-${Date.now()}-${Math.random()}`,
        type,
        name: '',
        amount: 0,
        category_id: null,
        source: 'manual',
        sort_order: cfEditor.lines.length,
      });
    }

    function removeCfLine(line) {
      cfEditor.lines = cfEditor.lines.filter((l) => l !== line && l.key !== line.key);
      recalcCfEditor();
    }

    function onCfLineAmountInput(line, event) {
      line.amount = parseInputNumber(event.target.value);
      event.target.value = formatInputNumber(line.amount);
      recalcCfEditor();
    }

    async function saveCashflowWorkbook() {
      if (!cfEditor.open || !cfEditor.branch_id) return;
      const lines = (cfEditor.lines || [])
        .filter((l) => String(l.name || '').trim() !== '')
        .map((l, idx) => ({
          type: l.type,
          name: String(l.name).trim(),
          amount: Number(l.amount || 0),
          category_id: l.category_id || null,
          source: l.source || 'manual',
          sort_order: idx,
        }));
      loading.value = true;
      try {
        const data = await api('/cashflow/workbook/save', {
          method: 'PUT',
          body: JSON.stringify({
            branch_id: Number(cfEditor.branch_id),
            year: Number(cfEditor.year),
            month: Number(cfEditor.month),
            note: cfEditor.note || null,
            lines,
          }),
        });
        toast(data.message || 'Alur kas disimpan.', 'success');
        fillCfEditor(data.data);
        await loadCashflowBoard();
      } catch (_) {
        toast('Gagal menyimpan alur kas.', 'error');
      } finally {
        loading.value = false;
      }
    }

    async function seedCashflowFromSystem() {
      if (!cfEditor.branch_id) return;
      if (cfEditor.exists) {
        const ok = await askConfirm({ title: 'Isi Ulang Alur Kas', message: 'Isi ulang dari transaksi + bagian toko?', detail: 'Perubahan yang belum disimpan akan diganti.', confirmLabel: 'Isi Ulang', danger: true });
        if (!ok) return;
      }
      loading.value = true;
      try {
        const data = await api('/cashflow/workbook/seed', {
          method: 'POST',
          body: JSON.stringify({
            branch_id: Number(cfEditor.branch_id),
            year: Number(cfEditor.year),
            month: Number(cfEditor.month),
          }),
        });
        toast(data.message || 'Pos diisi dari sistem.', 'success');
        fillCfEditor({ ...data.data, exists: cfEditor.exists });
      } catch (_) {
        toast('Gagal mengisi dari sistem.', 'error');
      } finally {
        loading.value = false;
      }
    }

    async function copyCashflowPrevious() {
      if (!cfEditor.branch_id) return;
      loading.value = true;
      try {
        const data = await api('/cashflow/workbook/copy-previous', {
          method: 'POST',
          body: JSON.stringify({
            branch_id: Number(cfEditor.branch_id),
            year: Number(cfEditor.year),
            month: Number(cfEditor.month),
          }),
        });
        toast(data.message || 'Pos disalin.', 'success');
        fillCfEditor({ ...data.data, exists: cfEditor.exists });
      } catch (_) {
        toast('Gagal menyalin pos bulan lalu.', 'error');
      } finally {
        loading.value = false;
      }
    }

    async function exportCashflowPdf(disposition = 'attachment') {
      const { from, to } = cashflowPeriodBounds();
      const prevType = reportForm.type;
      const prevFrom = reportForm.date_from;
      const prevTo = reportForm.date_to;
      const prevBranch = reportForm.branch_id;
      reportForm.type = 'alur-kas';
      reportForm.date_from = from;
      reportForm.date_to = to;
      reportForm.branch_id = isOwner.value ? (cashflowFilter.branch_id || '') : '';
      try {
        await exportReportPdf(disposition);
      } finally {
        reportForm.type = prevType;
        reportForm.date_from = prevFrom;
        reportForm.date_to = prevTo;
        reportForm.branch_id = prevBranch;
      }
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
        + Number(row.insentif_pic || 0)
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
      if (field === 'insentif_pic' && !row.is_pic) return;
      row[field] = parseInputNumber(e.target.value);
      e.target.value = formatInputNumber(row[field]);
      recomputePayrollRowTotal(row);
    }

    function applyPayrollPicFromBagiHasil(row) {
      if (!row || row.status === 'locked' || !row.is_pic) return;
      const auto = Number(row.insentif_pic_auto || 0);
      if (auto <= 0) {
        toast('Belum ada bagian PIC di Bagi Hasil untuk cabang/bulan ini.', 'info');
        return;
      }
      row.insentif_pic = auto;
      recomputePayrollRowTotal(row);
      toast(`PIC diisi dari Bagi Hasil: ${formatRp(auto)}`, 'success');
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
      // groups.rows adalah objek yang diedit di UI; samakan data agar save/lock
      // tidak mengirim salinan lama (insentif_acc/bonus/hutang tetap 0).
      const groups = data.groups || [];
      const rows = groups.length
        ? groups.flatMap((g) => g.rows || [])
        : (data.data || []);
      payrollBoard.value = {
        meta: data.meta || null,
        data: rows,
        groups,
      };
    }

    function payrollBoardRows() {
      const groups = payrollBoard.value.groups || [];
      if (groups.length) {
        return groups.flatMap((g) => g.rows || []);
      }
      return payrollBoard.value.data || [];
    }

    async function onPayrollFilterChange() {
      await loadPayrollBoard();
    }

    async function savePayrollBoard() {
      const allRows = payrollBoardRows();
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
            gapok: Number(r.gapok || 0),
            insentif_pic: r.is_pic ? Number(r.insentif_pic || 0) : 0,
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
      const rows = payrollBoardRows();
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
      const paidCount = payrollBoardRows().filter((r) => r.is_paid).length;
      const detailParts = [
        'Setelah dibuka, hitung ulang mengikuti absensi/closing/service terbaru.',
      ];
      if (paidCount > 0) {
        detailParts.unshift(
          `Ada ${paidCount} slip sudah Lunas — status bayar akan direset ke Belum.`,
        );
      }
      const ok = await askClosingConfirm({
        title: 'Buka Kunci Gaji',
        message: `Buka kunci gaji ${payrollMonthLabel.value}? Slip kembali ke draf.`,
        detail: detailParts.join(' '),
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
        const data = await api('/payrolls/unlock', {
          method: 'POST',
          body: JSON.stringify(payload),
        });
        toast(data.message || `Kunci gaji ${payrollMonthLabel.value} dibuka.`, 'success');
        await loadPayrollBoard();
      } catch (_) {
        // api() sudah menampilkan toast error
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

    async function markPayrollPaid(row) {
      if (row.status !== 'locked') {
        toast('Kunci slip dulu sebelum menandai dibayar.', 'error');
        return;
      }
      const ok = await askClosingConfirm({
        title: 'Tandai Dibayar',
        message: `Tandai gaji ${row.name} periode ${payrollMonthLabel.value} sudah dibayar?`,
        detail: `Total ${formatRp(row.total)}`,
        confirmLabel: 'Sudah Dibayar',
        danger: false,
      });
      if (!ok) return;
      loading.value = true;
      try {
        await api('/payrolls/mark-paid', {
          method: 'POST',
          body: JSON.stringify({
            employee_id: Number(row.employee_id),
            year: Number(payrollFilter.year),
            month: Number(payrollFilter.month),
          }),
        });
        toast(`Gaji ${row.name} ditandai sudah dibayar.`, 'success');
        await loadPayrollBoard();
        if (payrollDetail.open && payrollDetail.data?.employee_id === row.employee_id) {
          await openPayrollDetail(row);
        }
      } catch (_) {
        toast('Gagal menandai status bayar.', 'error');
      } finally {
        loading.value = false;
      }
    }

    async function markPayrollUnpaid(row) {
      const ok = await askClosingConfirm({
        title: 'Batalkan Status Bayar',
        message: `Batalkan tanda dibayar untuk ${row.name}?`,
        detail: payrollMonthLabel.value,
        confirmLabel: 'Batalkan Bayar',
        danger: true,
      });
      if (!ok) return;
      loading.value = true;
      try {
        await api('/payrolls/mark-unpaid', {
          method: 'POST',
          body: JSON.stringify({
            employee_id: Number(row.employee_id),
            year: Number(payrollFilter.year),
            month: Number(payrollFilter.month),
          }),
        });
        toast(`Status bayar ${row.name} dibatalkan.`, 'success');
        await loadPayrollBoard();
        if (payrollDetail.open && payrollDetail.data?.employee_id === row.employee_id) {
          await openPayrollDetail(row);
        }
      } catch (_) {
        toast('Gagal membatalkan status bayar.', 'error');
      } finally {
        loading.value = false;
      }
    }

    function closePayrollDetail() {
      payrollDetail.open = false;
    }

    function recalcPsEditor() {
      let income = 0;
      let expense = 0;
      for (const line of psEditor.lines || []) {
        const amount = Number(line.amount || 0);
        if (line.type === 'income') income += amount;
        else if (line.type === 'expense') expense += amount;
      }
      const pct = Number(psEditor.pic_share_pct || 0);
      const net = income - expense;
      psEditor.total_income = income;
      psEditor.total_expense = expense;
      psEditor.net_profit = net;
      psEditor.pic_amount = Math.round(net * (pct / 100) * 100) / 100;
    }

    function fillPsEditor(row) {
      if (!row) return;
      psEditor.open = true;
      psEditor.id = row.id || null;
      psEditor.branch_id = row.branch_id;
      psEditor.branch_name = row.branch_name || '';
      psEditor.branch_type = row.branch_type || '';
      psEditor.year = row.year;
      psEditor.month = row.month;
      psEditor.status = row.status || 'draft';
      psEditor.pic_name = row.pic_name || '';
      psEditor.pic_share_pct = Number(row.pic_share_pct ?? 50);
      psEditor.note = row.note || '';
      psEditor.exists = !!row.exists || !!row.id;
      psEditor.lines = (row.lines || []).map((l, idx) => ({
        key: `${l.type}-${idx}-${l.name || ''}`,
        type: l.type,
        name: l.name || '',
        amount: Number(l.amount || 0),
        sort_order: Number(l.sort_order ?? idx),
      }));
      recalcPsEditor();
    }

    function closePsEditor() {
      psEditor.open = false;
    }

    async function loadProfitShareBoard() {
      if (!canAccessProfitShares.value) return;
      loading.value = true;
      try {
        const params = new URLSearchParams();
        params.set('year', String(psFilter.year));
        params.set('month', String(psFilter.month));
        if (psFilter.branch_id) params.set('branch_id', String(psFilter.branch_id));
        const data = await api(`/profit-shares/board?${params.toString()}`);
        psBoard.value = data.data || { rows: [], meta: null };
        if (psEditor.open && psEditor.branch_id) {
          const found = (psBoard.value.rows || []).find((r) => Number(r.branch_id) === Number(psEditor.branch_id));
          if (found) fillPsEditor(found);
        }
      } catch (_) {
        toast('Gagal memuat board bagi hasil.', 'error');
        psBoard.value = { rows: [], meta: null };
      } finally {
        loading.value = false;
      }
    }

    function onPsFilterChange() {
      closePsEditor();
      loadProfitShareBoard();
    }

    async function openPsEditor(row) {
      fillPsEditor(row);
      if (!row.exists && !row.id) {
        // biarkan template virtual; user bisa salin bulan lalu
      }
    }

    function addPsLine(type) {
      if (psEditor.status === 'locked') return;
      psEditor.lines.push({
        key: `${type}-${Date.now()}-${Math.random()}`,
        type,
        name: '',
        amount: 0,
        sort_order: psEditor.lines.length,
      });
    }

    function removePsLine(line) {
      if (psEditor.status === 'locked') return;
      psEditor.lines = psEditor.lines.filter((l) => l !== line && l.key !== line.key);
      recalcPsEditor();
    }

    function onPsLineAmountInput(line, event) {
      line.amount = parseInputNumber(event.target.value);
      event.target.value = formatInputNumber(line.amount);
      recalcPsEditor();
    }

    async function saveProfitShare() {
      if (!psEditor.open || !psEditor.branch_id) return;
      if (psEditor.status === 'locked') {
        toast('Periode terkunci. Buka kunci terlebih dahulu.', 'error');
        return;
      }
      const lines = (psEditor.lines || [])
        .filter((l) => String(l.name || '').trim() !== '')
        .map((l, idx) => ({
          type: l.type,
          name: String(l.name).trim(),
          amount: Number(l.amount || 0),
          sort_order: idx,
        }));
      loading.value = true;
      try {
        const data = await api('/profit-shares/save', {
          method: 'PUT',
          body: JSON.stringify({
            branch_id: Number(psEditor.branch_id),
            year: Number(psEditor.year),
            month: Number(psEditor.month),
            pic_name: psEditor.pic_name || null,
            pic_share_pct: Number(psEditor.pic_share_pct),
            note: psEditor.note || null,
            lines,
          }),
        });
        toast(data.message || 'Bagi hasil disimpan.', 'success');
        fillPsEditor(data.data);
        await loadProfitShareBoard();
      } catch (_) {
        toast('Gagal menyimpan bagi hasil.', 'error');
      } finally {
        loading.value = false;
      }
    }

    async function lockProfitShare() {
      if (!psEditor.branch_id) return;
      const ok = await askConfirm({ title: 'Kunci Bagi Hasil', message: 'Kunci bagi hasil cabang ini?', detail: 'Data tidak bisa diubah sampai dibuka lagi.', confirmLabel: 'Kunci', danger: false });
      if (!ok) return;
      loading.value = true;
      try {
        if (!psEditor.exists && !psEditor.id) {
          const lines = (psEditor.lines || [])
            .filter((l) => String(l.name || '').trim() !== '')
            .map((l, idx) => ({
              type: l.type,
              name: String(l.name).trim(),
              amount: Number(l.amount || 0),
              sort_order: idx,
            }));
          await api('/profit-shares/save', {
            method: 'PUT',
            body: JSON.stringify({
              branch_id: Number(psEditor.branch_id),
              year: Number(psEditor.year),
              month: Number(psEditor.month),
              pic_name: psEditor.pic_name || null,
              pic_share_pct: Number(psEditor.pic_share_pct),
              note: psEditor.note || null,
              lines,
            }),
          });
        }
        const data = await api('/profit-shares/lock', {
          method: 'POST',
          body: JSON.stringify({
            branch_id: Number(psEditor.branch_id),
            year: Number(psEditor.year),
            month: Number(psEditor.month),
          }),
        });
        toast(data.message || 'Bagi hasil dikunci.', 'success');
        fillPsEditor(data.data);
        await loadProfitShareBoard();
      } catch (_) {
        toast('Gagal mengunci bagi hasil.', 'error');
      } finally {
        loading.value = false;
      }
    }

    async function unlockProfitShare() {
      if (!psEditor.branch_id) return;
      const ok = await askConfirm({ title: 'Buka Kunci Bagi Hasil', message: 'Buka kunci bagi hasil cabang ini?', confirmLabel: 'Buka Kunci', danger: true });
      if (!ok) return;
      loading.value = true;
      try {
        const data = await api('/profit-shares/unlock', {
          method: 'POST',
          body: JSON.stringify({
            branch_id: Number(psEditor.branch_id),
            year: Number(psEditor.year),
            month: Number(psEditor.month),
          }),
        });
        toast(data.message || 'Kunci dibuka.', 'success');
        fillPsEditor(data.data);
        await loadProfitShareBoard();
      } catch (_) {
        toast('Gagal membuka kunci bagi hasil.', 'error');
      } finally {
        loading.value = false;
      }
    }

    async function copyProfitSharePrevious() {
      if (!psEditor.branch_id || psEditor.status === 'locked') return;
      loading.value = true;
      try {
        const data = await api('/profit-shares/copy-previous', {
          method: 'POST',
          body: JSON.stringify({
            branch_id: Number(psEditor.branch_id),
            year: Number(psEditor.year),
            month: Number(psEditor.month),
          }),
        });
        toast(data.message || 'Pos disalin.', 'success');
        fillPsEditor({ ...data.data, exists: psEditor.exists });
      } catch (_) {
        toast('Gagal menyalin pos bulan lalu.', 'error');
      } finally {
        loading.value = false;
      }
    }

    /** Detail gaji: sembunyikan komponen yang nilainya 0 / kosong. */
    function payrollDetailHas(value) {
      const n = Number(value);
      return Number.isFinite(n) && n !== 0;
    }

    /** Normalisasi HP Indonesia → digit internasional (62…) untuk wa.me */
    function toWhatsAppPhone(raw) {
      let digits = String(raw || '').replace(/\D+/g, '');
      if (!digits) return '';
      if (digits.startsWith('62')) return digits;
      if (digits.startsWith('0')) return `62${digits.slice(1)}`;
      if (digits.startsWith('8')) return `62${digits}`;
      return digits;
    }

    function buildPayrollWhatsAppText(row) {
      if (!row) return '';
      const lines = [
        '*Slip Gaji — BMS*',
        `Nama: ${row.name || '—'}`,
        `Cabang: ${row.branch_name || '—'}`,
        `Periode: ${payrollMonthLabel.value}`,
        '',
      ];
      const pushIf = (label, value, fmt = (v) => String(v)) => {
        if (!payrollDetailHas(value)) return;
        lines.push(`${label}: ${fmt(value)}`);
      };
      pushIf('Hadir', row.present_days, (v) => `${v} hari`);
      pushIf('Gapok', row.gapok, formatRp);
      if (row.is_pic) pushIf('Insentif PIC', row.insentif_pic, formatRp);
      pushIf('Qty Closing', row.closing_qty);
      pushIf('Insentif HP', row.insentif_hp, formatRp);
      pushIf('Service 50%', row.service_incentive, formatRp);
      pushIf('Insentif ACC', row.insentif_acc, formatRp);
      pushIf('Bonus', row.bonus_absen, formatRp);
      pushIf('Hutang', row.hutang, formatRp);
      pushIf('Kasbon', row.kasbon != null ? row.kasbon : row.pengeluaran, formatRp);
      lines.push('');
      const totalNum = Number(row.total || 0);
      lines.push(`*Total bersih: ${formatRp(row.total)}*`);
      if (totalNum < 0) {
        lines.push('');
        lines.push(`Catatan: Total minus berarti masih ada hutang ke toko sebesar ${formatRp(Math.abs(totalNum))}.`);
      }
      if (row.note) {
        lines.push('');
        lines.push(`Catatan: ${row.note}`);
      }
      return lines.join('\n');
    }

    function openPayrollWhatsApp(row) {
      const phone = toWhatsAppPhone(row?.phone);
      if (!phone) {
        toast('Nomor HP karyawan belum diisi. Lengkapi di Data Karyawan.', 'error');
        return;
      }
      const text = buildPayrollWhatsAppText(row);
      const url = `https://wa.me/${phone}?text=${encodeURIComponent(text)}`;
      const win = window.open(url, '_blank', 'noopener,noreferrer');
      if (!win) {
        toast('Popup diblokir browser. Izinkan popup untuk membuka WhatsApp.', 'error');
      }
    }

    function buildClosingWhatsAppText(row) {
      if (!row) return '';
      const periode = reportResult.value?.data?.periode_label
        || reportResult.value?.meta?.periode
        || 'periode ini';
      const pct = row.pct != null ? `${row.pct}%` : '—';
      const selisih = Number(row.selisih || 0);
      const selisihLabel = selisih > 0 ? `+${selisih}` : String(selisih);
      const lines = [
        '*Update Closing — BMS*',
        `Nama: ${row.nama || '—'}`,
        `Cabang: ${row.cabang || '—'}`,
        `Periode: ${periode}`,
        '',
        `Closing: ${row.qty ?? 0} / Target: ${row.target ?? 0}`,
        `Capaian: ${pct}`,
        `Status: ${row.status_label || (row.tercapai ? 'Tercapai' : 'Belum tercapai')}`,
        `Selisih: ${selisihLabel}`,
      ];
      if (!row.tercapai) {
        const kurang = Math.max(0, Number(row.target || 0) - Number(row.qty || 0));
        lines.push('');
        lines.push(`Masih kurang ${kurang} dari target. Mohon dikejar ya.`);
      } else {
        lines.push('');
        lines.push('Target tercapai. Terima kasih atas kerja kerasnya.');
      }
      return lines.join('\n');
    }

    function openClosingWhatsApp(row) {
      const phone = toWhatsAppPhone(row?.phone);
      if (!phone) {
        toast('Nomor HP karyawan belum diisi. Lengkapi di Data Karyawan.', 'error');
        return;
      }
      const text = buildClosingWhatsAppText(row);
      const url = `https://wa.me/${phone}?text=${encodeURIComponent(text)}`;
      const win = window.open(url, '_blank', 'noopener,noreferrer');
      if (!win) {
        toast('Popup diblokir browser. Izinkan popup untuk membuka WhatsApp.', 'error');
      }
    }

    function formatTxDailyDateLabel(ymd) {
      if (!ymd) return '—';
      try {
        return new Intl.DateTimeFormat('id-ID', {
          weekday: 'long', day: 'numeric', month: 'long', year: 'numeric',
        }).format(new Date(`${ymd}T12:00:00`));
      } catch (_) {
        return ymd;
      }
    }

    async function openTxDailyReport(opts = {}) {
      const date = (opts.date || txForm.transaction_date || '').toString().slice(0, 10);
      if (!date) {
        toast('Pilih tanggal transaksi dulu.', 'error');
        return;
      }
      let branchId = opts.branch_id != null && opts.branch_id !== ''
        ? opts.branch_id
        : (isOwner.value ? txForm.branch_id : (user.value?.branch_id || ''));
      if (isOwner.value && !branchId) {
        toast('Pilih cabang dulu untuk laporan harian.', 'error');
        return;
      }
      const branchName = opts.branch_name
        || (isOwner.value
          ? (branches.value.find((b) => Number(b.id) === Number(branchId))?.name || '—')
          : (user.value?.branch?.name || 'Cabang Anda'));

      txDailyReport.open = true;
      txDailyReport.loading = true;
      txDailyReport.date = date;
      txDailyReport.branch_id = branchId;
      txDailyReport.branch_name = branchName;
      txDailyReport.rows = [];
      txDailyReport.total_income = 0;
      txDailyReport.total_expense = 0;
      try {
        const params = new URLSearchParams();
        params.set('date_from', date);
        params.set('date_to', date);
        params.set('per_page', '200');
        if (isOwner.value && branchId) params.set('branch_id', String(branchId));
        const data = await api(`/transactions?${params.toString()}`);
        const pageData = data.data;
        const rows = pageData?.data || (Array.isArray(pageData) ? pageData : []);
        txDailyReport.rows = rows.map((t) => ({
          id: t.id,
          tanggal: t.transaction_date,
          tipe: t.category?.type || '',
          kategori: t.category?.name || '—',
          akun: t.account?.name || '—',
          account_id: t.account_id || t.account?.id || null,
          account_code: t.account?.code || '',
          nominal: Number(t.amount || 0),
          keterangan: t.description || '',
        }));
        txDailyReport.total_income = txDailyReport.rows
          .filter((r) => r.tipe === 'income')
          .reduce((s, r) => s + r.nominal, 0);
        txDailyReport.total_expense = txDailyReport.rows
          .filter((r) => r.tipe === 'expense')
          .reduce((s, r) => s + r.nominal, 0);
      } catch (_) {
        txDailyReport.open = false;
      } finally {
        txDailyReport.loading = false;
      }
    }

    function closeTxDailyReport() {
      txDailyReport.open = false;
    }

    function txDailyRowIsCash(r) {
      if (r.account_code && String(r.account_code).toLowerCase() === 'cash') return true;
      if (txCashAccountId.value && Number(r.account_id) === Number(txCashAccountId.value)) return true;
      return String(r.akun || '').trim().toLowerCase() === 'cash';
    }

    const txDailyCashSummary = computed(() => {
      const rows = txDailyReport.rows || [];
      let salesCash = 0;
      let hpSalesCash = 0;
      let pulsaSalesCash = 0;
      let cashIn = 0;
      let cashOut = 0;
      let bankIn = 0;
      let bankOut = 0;
      rows.forEach((r) => {
        const cash = txDailyRowIsCash(r);
        if (cash) {
          if (r.tipe === 'income') {
            cashIn += r.nominal;
            if (isSalesCategoryName(r.kategori)) salesCash += r.nominal;
            if (isHpSalesCategoryName(r.kategori)) hpSalesCash += r.nominal;
            if (isPulsaSalesCategoryName(r.kategori)) pulsaSalesCash += r.nominal;
          } else {
            cashOut += r.nominal;
          }
        } else if (r.tipe === 'income') {
          bankIn += r.nominal;
        } else {
          bankOut += r.nominal;
        }
      });
      return {
        salesCash,
        hpSalesCash,
        pulsaSalesCash,
        cashIn,
        cashOut,
        cashNet: cashIn - cashOut,
        bankIn,
        bankOut,
        netAll: (txDailyReport.total_income || 0) - (txDailyReport.total_expense || 0),
      };
    });

    function buildTxDailyWhatsAppText() {
      const s = txDailyCashSummary.value;
      const lines = [
        '*Laporan Harian Transaksi — BMS*',
        `Tanggal: ${formatTxDailyDateLabel(txDailyReport.date)}`,
        `Cabang: ${txDailyReport.branch_name || '—'}`,
        '',
        '*Kunci cek fisik*',
        `*Penjualan HP (Cash):* ${formatRp(s.hpSalesCash)}`,
        '',
      ];
      if (s.pulsaSalesCash) lines.push(`Penjualan Pulsa (Cash): ${formatRp(s.pulsaSalesCash)}`);
      lines.push(`Keluar Cash: ${formatRp(s.cashOut)}`);
      if (s.bankOut) lines.push(`Keluar bank: ${formatRp(s.bankOut)}`);
      lines.push(`Laba/Rugi semua akun: ${formatRp(s.netAll)}`);
      lines.push(`Jumlah transaksi: ${txDailyReport.rows.length}`);
      lines.push('');

      const income = txDailyReport.rows.filter((r) => r.tipe === 'income');
      const expense = txDailyReport.rows.filter((r) => r.tipe === 'expense');
      if (income.length) {
        lines.push('*Detail Pemasukan*');
        income.forEach((r, i) => {
          const note = r.keterangan ? ` (${r.keterangan})` : '';
          const akun = r.akun ? ` [${r.akun}]` : '';
          lines.push(`${i + 1}. ${r.kategori}${akun} — ${formatRp(r.nominal)}${note}`);
        });
        lines.push('');
      }
      if (expense.length) {
        lines.push('*Detail Pengeluaran*');
        expense.forEach((r, i) => {
          const note = r.keterangan ? ` (${r.keterangan})` : '';
          const akun = r.akun ? ` [${r.akun}]` : '';
          lines.push(`${i + 1}. ${r.kategori}${akun} — ${formatRp(r.nominal)}${note}`);
        });
        lines.push('');
      }
      if (!txDailyReport.rows.length) {
        lines.push('_Belum ada transaksi pada tanggal ini._');
      }
      return lines.join('\n').trim();
    }

    function shareTxDailyWhatsApp() {
      const text = buildTxDailyWhatsAppText();
      if (!text) {
        toast('Tidak ada isi laporan untuk dikirim.', 'error');
        return;
      }
      const phone = toWhatsAppPhone(txDailyReport.wa_phone);
      const url = phone
        ? `https://wa.me/${phone}?text=${encodeURIComponent(text)}`
        : `https://api.whatsapp.com/send?text=${encodeURIComponent(text)}`;
      const win = window.open(url, '_blank', 'noopener,noreferrer');
      if (!win) {
        toast('Popup diblokir browser. Izinkan popup untuk membuka WhatsApp.', 'error');
      }
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
      else if (wwTab.value === 'job-types') await loadWwJobTypeCatalog();
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
      // Multi-input: biarkan teknisi kosong sampai user memilih
      ensureWwDraftRows(3);
    }

    async function loadWwJobTypes() {
      const params = wwBranchParams();
      if (!params) {
        wwJobTypes.value = [];
        return;
      }
      const data = await api(`/workshop-wages/job-types?${params.toString()}`);
      wwJobTypes.value = (data.data || []).map((t) => ({
        id: t.id,
        name: t.name,
        default_amount: t.default_amount != null ? Number(t.default_amount) : null,
        status: t.status || 'active',
        sort_order: Number(t.sort_order || 0),
      }));
    }

    async function loadWwJobTypeCatalog() {
      const params = wwBranchParams({ include_inactive: '1' });
      if (!params) {
        wwJobTypeCatalog.value = [];
        return;
      }
      const data = await api(`/workshop-wages/job-types?${params.toString()}`);
      wwJobTypeCatalog.value = (data.data || []).map((t) => ({
        id: t.id,
        name: t.name,
        default_amount: t.default_amount != null ? Number(t.default_amount) : null,
        status: t.status || 'active',
        sort_order: Number(t.sort_order || 0),
      }));
      // Sinkron daftar aktif untuk chip/dropdown harian.
      wwJobTypes.value = wwJobTypeCatalog.value.filter((t) => t.status === 'active');
    }

    function resetWwJobTypeForm() {
      wwJobTypeForm.id = null;
      wwJobTypeForm.name = '';
      wwJobTypeForm.default_amount = '';
      wwJobTypeForm.status = 'active';
      wwJobTypeForm.sort_order = (wwJobTypeCatalog.value?.length || 0) + 1;
    }

    function editWwJobType(row) {
      wwJobTypeForm.id = row.id;
      wwJobTypeForm.name = row.name;
      wwJobTypeForm.default_amount =
        row.default_amount != null ? formatInputNumber(row.default_amount) : '';
      wwJobTypeForm.status = row.status || 'active';
      wwJobTypeForm.sort_order = Number(row.sort_order || 0);
    }

    async function submitWwJobType() {
      if (!(await ensureWwBranch())) {
        toast('Pilih cabang bengkel dulu.', 'error');
        return;
      }
      const name = String(wwJobTypeForm.name || '').trim();
      if (!name) {
        toast('Nama jenis kerja wajib diisi.', 'error');
        return;
      }
      const payload = {
        name,
        default_amount: wwJobTypeForm.default_amount
          ? parseInputNumber(wwJobTypeForm.default_amount)
          : null,
        status: wwJobTypeForm.status,
        sort_order: Number(wwJobTypeForm.sort_order || 0),
      };
      if (isOwner.value) payload.branch_id = Number(wwFilter.branch_id);
      loading.value = true;
      try {
        if (wwJobTypeForm.id) {
          await api(`/workshop-wages/job-types/${wwJobTypeForm.id}`, {
            method: 'PUT',
            body: JSON.stringify(payload),
          });
          toast('Jenis kerja diperbarui.', 'success');
        } else {
          await api('/workshop-wages/job-types', {
            method: 'POST',
            body: JSON.stringify(payload),
          });
          toast('Jenis kerja ditambah.', 'success');
        }
        resetWwJobTypeForm();
        await loadWwJobTypeCatalog();
      } catch (_) {
        // api() sudah toast
      } finally {
        loading.value = false;
      }
    }

    async function toggleWwJobTypeStatus(row) {
      const next = row.status === 'active' ? 'inactive' : 'active';
      loading.value = true;
      try {
        await api(`/workshop-wages/job-types/${row.id}`, {
          method: 'PUT',
          body: JSON.stringify({ status: next }),
        });
        toast(next === 'active' ? 'Jenis kerja diaktifkan.' : 'Jenis kerja dinonaktifkan.', 'success');
        await loadWwJobTypeCatalog();
      } catch (_) {
        // api() sudah toast
      } finally {
        loading.value = false;
      }
    }

    async function deleteWwJobType(row) {
      const ok = await askClosingConfirm({
        title: 'Hapus Jenis Kerja',
        message: `Hapus atau nonaktifkan “${row.name}”?`,
        detail: 'Jika sudah dipakai di transaksi, jenis akan dinonaktifkan saja.',
        confirmLabel: 'Hapus',
        danger: true,
      });
      if (!ok) return;
      loading.value = true;
      try {
        const data = await api(`/workshop-wages/job-types/${row.id}`, { method: 'DELETE' });
        toast(data.message || 'Jenis kerja dihapus.', 'success');
        if (wwJobTypeForm.id === row.id) resetWwJobTypeForm();
        await loadWwJobTypeCatalog();
      } catch (_) {
        // api() sudah toast
      } finally {
        loading.value = false;
      }
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

    function wwPreviousMonthLabel() {
      const y = Number(wwFilter.year);
      const m = Number(wwFilter.month);
      if (!y || !m) return 'bulan sebelumnya';
      const d = new Date(y, m - 2, 1);
      return `${d.getMonth() + 1}/${d.getFullYear()}`;
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

    async function copyWwSettingsFromPrevious() {
      if (!(await ensureWwBranch())) {
        toast('Pilih cabang bengkel dulu.', 'error');
        return;
      }
      if (!wwSettingsRows.value.length) {
        toast('Tidak ada teknisi untuk diatur.', 'error');
        return;
      }
      const prevLabel = wwPreviousMonthLabel();
      const ok = await askClosingConfirm({
        title: 'Salin dari Bulan Sebelumnya',
        message: `Salin persen teknisi dari ${prevLabel} ke ${wwFilter.month}/${wwFilter.year}?`,
        detail: 'Nilai yang sudah ada di bulan ini akan diganti untuk teknisi yang punya data di bulan sebelumnya.',
        confirmLabel: 'Salin',
      });
      if (!ok) return;
      loading.value = true;
      try {
        const payload = {
          year: Number(wwFilter.year),
          month: Number(wwFilter.month),
        };
        if (isOwner.value) payload.branch_id = Number(wwFilter.branch_id);
        const data = await api('/workshop-wages/settings/copy-previous', {
          method: 'POST',
          body: JSON.stringify(payload),
        });
        wwSettingsRows.value = (data.data || []).map((r) => ({
          ...r,
          tech_share_pct: Number(r.tech_share_pct ?? 50),
        }));
        wwSettingsMeta.value = data.meta || null;
        toast(data.message || 'Persen berhasil disalin.', 'success');
        if (wwTab.value === 'weekly') await loadWwWeeksAndDetail();
      } catch (_) {
        // api() sudah menampilkan toast error
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
      if (!wwJobForm.id) {
        wwJobForm.job_date = wwDailyDate.value;
      }
      ensureWwDraftRows(3);
    }

    async function onWwDailyDateChange() {
      wwJobForm.job_date = wwDailyDate.value;
      if (wwJobForm.id) resetWwJobForm();
      await loadWwDailyJobs();
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
      wwDailyDate.value = job.job_date;
      toast('Mode ubah: edit baris tersimpan di bawah.', 'info');
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
        // api() sudah toast
      } finally {
        loading.value = false;
      }
    }

    async function submitWwDraftBatch() {
      if (!(await ensureWwBranch())) {
        toast('Pilih cabang bengkel dulu.', 'error');
        return;
      }
      if (wwJobForm.id) {
        toast('Selesaikan atau batalkan mode ubah dulu.', 'error');
        return;
      }
      if (!wwDailyDate.value) {
        toast('Pilih tanggal kerja dulu.', 'error');
        return;
      }
      const items = [];
      for (let i = 0; i < wwDraftRows.value.length; i++) {
        const r = wwDraftRows.value[i];
        const jobType = String(r.job_type || '').trim();
        const amount = parseInputNumber(r.amount);
        const hasAny = jobType || amount || String(r.note || '').trim();
        if (!hasAny) continue;
        if (!r.employee_id || !jobType || !amount) {
          toast(`Lengkapi baris ${i + 1}: jenis, teknisi, dan nominal.`, 'error');
          return;
        }
        items.push({
          employee_id: Number(r.employee_id),
          job_type: jobType,
          amount,
          note: String(r.note || '').trim() || null,
        });
      }
      if (!items.length) {
        toast('Isi minimal satu baris kerja.', 'error');
        return;
      }
      const ok = await askClosingConfirm({
        title: 'Simpan Multi Kerja',
        message: `Simpan ${items.length} kerja untuk ${formatWwDateLabel(wwDailyDate.value)}?`,
        detail: `Total nominal ${formatRp(items.reduce((s, it) => s + it.amount, 0))}`,
        confirmLabel: 'Simpan semua',
      });
      if (!ok) return;
      loading.value = true;
      try {
        const payload = {
          job_date: wwDailyDate.value,
          items,
        };
        if (isOwner.value) payload.branch_id = Number(wwFilter.branch_id);
        const data = await api('/workshop-wages/jobs/batch', {
          method: 'POST',
          body: JSON.stringify(payload),
        });
        toast(data.message || 'Kerja berhasil disimpan.', 'success');
        resetWwDraftRows();
        await loadWwDailyJobs();
        await loadWwJobTypes();
      } catch (_) {
        // api() sudah toast
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

    const closingIsLocked = computed(() => !!closingBoard.value?.meta?.is_locked);

    function closingGroupLocked(g) {
      return !!g?.is_locked;
    }

    function canEditClosingRow(row) {
      // Owner selalu boleh edit; admin diblokir jika periode cabang terkunci.
      if (isOwner.value) return true;
      if (closingIsLocked.value) return false;
      const group = (closingBoard.value?.groups || []).find((g) =>
        (g.rows || []).some((r) => r.employee_id === row.employee_id)
      );
      return !closingGroupLocked(group);
    }

    async function lockClosingBoard(branchId, locked) {
      if (!isOwner.value) return;
      const id = Number(branchId || closingFilter.branch_id || closingBoard.value?.meta?.branch_id || 0);
      if (!id) {
        toast('Pilih cabang dulu untuk mengunci/membuka.', 'error');
        return;
      }
      const branchName = (konterBranches.value || []).find((b) => Number(b.id) === id)?.name
        || (closingBoard.value?.groups || []).find((g) => Number(g.branch_id) === id)?.branch_name
        || 'cabang ini';
      const ok = await askClosingConfirm({
        title: locked ? 'Kunci Target Closingan' : 'Buka Kunci Closingan',
        message: locked
          ? `Kunci closingan ${branchName} untuk bulan ${closingFilter.month}/${closingFilter.year}?`
          : `Buka kunci closingan ${branchName} untuk bulan ${closingFilter.month}/${closingFilter.year}?`,
        detail: locked
          ? 'Admin cabang tidak dapat mengubah qty/target sampai dikunci dibuka.'
          : 'Admin cabang dapat mengubah data lagi.',
        confirmLabel: locked ? 'Kunci' : 'Buka Kunci',
        danger: !locked,
      });
      if (!ok) return;
      loading.value = true;
      try {
        const data = await api(locked ? '/closings/lock' : '/closings/unlock', {
          method: 'POST',
          body: JSON.stringify({
            branch_id: id,
            year: Number(closingFilter.year),
            month: Number(closingFilter.month),
          }),
        });
        toast(data.message || (locked ? 'Dikunci.' : 'Kunci dibuka.'), 'success');
        await loadClosingBoard();
      } catch (_) {
      } finally {
        loading.value = false;
      }
    }

    async function saveClosingDaily(row, day, rawValue, inputEl = null) {
      if (!canEditClosingRow(row)) {
        toast('Aksi ditolak: Target closingan periode ini telah dikunci oleh Owner.', 'error');
        if (inputEl) inputEl.value = Number(row.daily?.[day] || 0) || '';
        return;
      }
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
      if (!canEditClosingRow(row)) {
        toast('Aksi ditolak: Target closingan periode ini telah dikunci oleh Owner.', 'error');
        if (inputEl) inputEl.value = Number(row.target || 0) || '';
        return;
      }
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
      const ok = await askConfirm({ title: 'Hapus Karyawan', message: 'Hapus karyawan ini?', confirmLabel: 'Hapus', danger: true });
      if (!ok) return;
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

    function buildServiceQuery(pageNum = 1) {
      const params = new URLSearchParams();
      if (isOwner.value && serviceFilter.branch_id) params.set('branch_id', String(serviceFilter.branch_id));
      if (serviceFilter.employee_id) params.set('employee_id', String(serviceFilter.employee_id));
      if (serviceFilter.date_from) params.set('date_from', serviceFilter.date_from);
      if (serviceFilter.date_to) params.set('date_to', serviceFilter.date_to);
      if (serviceFilter.q.trim()) params.set('q', serviceFilter.q.trim());
      params.set('page', String(pageNum || 1));
      params.set('per_page', String(serviceMeta.value.per_page || 20));
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

    async function loadServiceRecords(pageNum = 1) {
      // Filter cabang hanya konter; buang pilihan bengkel jika masih tersimpan.
      if (isOwner.value && serviceFilter.branch_id) {
        const ok = (konterBranches.value || []).some((b) => String(b.id) === String(serviceFilter.branch_id));
        if (!ok) serviceFilter.branch_id = '';
      }
      loading.value = true;
      try {
        const data = await api(`/service-records${buildServiceQuery(pageNum)}`);
        const page = data.data || {};
        serviceRecords.value = page.data || (Array.isArray(page) ? page : []);
        serviceMeta.value = {
          total: Number(page.total ?? serviceRecords.value.length ?? 0),
          current_page: Number(page.current_page || pageNum || 1),
          last_page: Number(page.last_page || 1),
          per_page: Number(page.per_page || serviceMeta.value.per_page || 20),
        };
        serviceSummary.value = data.summary || { jumlah: 0, total_modal: 0, total_harga: 0, total_profit: 0 };
        await loadServiceTechnicians();
      } catch (_) {
        serviceRecords.value = [];
      } finally {
        loading.value = false;
      }
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
      if (!serviceForm.id) {
        toast('Gunakan form multi input untuk menambah catatan.', 'error');
        return;
      }
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
        await api(`/service-records/${serviceForm.id}`, { method: 'PUT', body: JSON.stringify(payload) });
        toast('Catatan servis diperbarui.', 'success');
        resetServiceForm();
        await loadServiceRecords();
      } catch (_) {
      } finally {
        loading.value = false;
      }
    }

    async function submitSvcDraftBatch() {
      if (serviceForm.id) {
        toast('Selesaikan atau batalkan mode ubah dulu.', 'error');
        return;
      }
      if (!serviceForm.service_date) {
        toast('Pilih tanggal servis dulu.', 'error');
        return;
      }
      const items = [];
      for (let i = 0; i < svcDraftRows.value.length; i++) {
        const r = svcDraftRows.value[i];
        const brand = String(r.brand || '').trim();
        const deviceType = String(r.device_type || '').trim();
        const damage = String(r.damage || '').trim();
        const notes = String(r.notes || '').trim();
        const cost = parseInputNumber(r.cost);
        const price = parseInputNumber(r.price);
        const hasAny = r.employee_id || brand || deviceType || damage || notes || r.cost || r.price;
        if (!hasAny) continue;
        if (!r.employee_id || !brand || !deviceType || !damage) {
          toast(`Lengkapi baris ${i + 1}: teknisi, merek, type, dan kerusakan.`, 'error');
          return;
        }
        if (cost < 0 || price < 0) {
          toast(`Modal/harga tidak valid pada baris ${i + 1}.`, 'error');
          return;
        }
        items.push({
          employee_id: Number(r.employee_id),
          brand,
          device_type: deviceType,
          damage,
          cost,
          price,
          notes: notes || null,
        });
      }
      if (!items.length) {
        toast('Isi minimal satu baris servis.', 'error');
        return;
      }
      const totalProfit = items.reduce((s, it) => s + (it.price - it.cost), 0);
      const ok = await askClosingConfirm({
        title: 'Simpan Multi Servis',
        message: `Simpan ${items.length} catatan servis tanggal ${serviceForm.service_date}?`,
        detail: `Total profit ${formatRp(totalProfit)}`,
        confirmLabel: 'Simpan semua',
      });
      if (!ok) return;
      loading.value = true;
      try {
        const data = await api('/service-records/batch', {
          method: 'POST',
          body: JSON.stringify({
            service_date: serviceForm.service_date,
            items,
          }),
        });
        toast(data.message || 'Catatan servis berhasil disimpan.', 'success');
        resetSvcDraftRows();
        await loadServiceRecords();
      } catch (_) {
        // api() sudah toast
      } finally {
        loading.value = false;
      }
    }

    async function deleteService(id) {
      const ok = await askConfirm({ title: 'Hapus Servis', message: 'Hapus catatan servis ini?', confirmLabel: 'Hapus', danger: true });
      if (!ok) return;
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

    function applyPulsaDaily(data) {
      pulsaForm.id = data.id || null;
      pulsaForm.cash_on_hand = formatInputNumber(data.cash_on_hand || 0);
      pulsaForm.note = data.note || '';
      pulsaForm.is_new = !!data.is_new;
      pulsaBalances.value = (data.balances || []).map((b) => ({
        pulsa_provider_id: b.pulsa_provider_id,
        provider_name: b.provider_name,
        opening_balance: formatInputNumber(b.opening_balance || 0),
        topup_amount: formatInputNumber(b.topup_amount || 0),
        closing_balance: data.is_new ? '' : formatInputNumber(b.closing_balance || 0),
      }));
      const expenses = (data.expenses || []).map((e) => blankPulsaExpense({
        name: e.name || '',
        amount: formatInputNumber(e.amount || 0),
      }));
      pulsaExpenses.value = expenses;
      ensurePulsaExpenseRows(3);
    }

    async function loadPulsaProviders() {
      if (!canAccessPulsa.value) return;
      const params = new URLSearchParams();
      if (isOwner.value) {
        if (!pulsaBranchId.value) {
          pulsaProviders.value = [];
          return;
        }
        params.set('branch_id', pulsaBranchId.value);
      }
      try {
        const data = await api(`/pulsa-profits/providers?${params.toString()}`);
        pulsaProviders.value = data.data || [];
      } catch (_) {
        pulsaProviders.value = [];
      }
    }

    async function loadPulsaDaily() {
      if (!canAccessPulsa.value) return;
      if (isOwner.value && !pulsaBranchId.value) {
        pulsaBalances.value = [];
        pulsaForm.id = null;
        pulsaForm.is_new = true;
        return;
      }
      const params = new URLSearchParams({ date: pulsaDate.value });
      if (isOwner.value) params.set('branch_id', pulsaBranchId.value);
      loading.value = true;
      try {
        const data = await api(`/pulsa-profits/daily?${params.toString()}`);
        applyPulsaDaily(data.data || {});
      } catch (_) {
        pulsaBalances.value = [];
      } finally {
        loading.value = false;
      }
    }

    async function loadPulsaHistory() {
      if (!canAccessPulsa.value) return;
      const params = new URLSearchParams();
      if (isOwner.value && pulsaBranchId.value) params.set('branch_id', pulsaBranchId.value);
      if (!isOwner.value && user.value?.branch_id) {
        // admin: server pakai branch sendiri
      }
      try {
        const data = await api(`/pulsa-profits?${params.toString()}`);
        pulsaHistory.value = data.data || [];
      } catch (_) {
        pulsaHistory.value = [];
      }
    }

    async function loadPulsaProfitPage() {
      if (!canAccessPulsa.value) return;
      if (isOwner.value && !pulsaBranchId.value) {
        const konter = (branches.value || []).find((b) => b.allows_service !== false);
        if (konter) pulsaBranchId.value = konter.id;
      }
      await loadPulsaProviders();
      await loadPulsaDaily();
      await loadPulsaHistory();
    }

    async function submitPulsaProvider() {
      if (!canInputPulsa.value) return;
      const name = String(pulsaProviderForm.name || '').trim();
      if (!name) {
        toast('Nama provider wajib diisi.', 'error');
        return;
      }
      loading.value = true;
      try {
        await api('/pulsa-profits/providers', {
          method: 'POST',
          body: JSON.stringify({ name }),
        });
        toast('Provider ditambahkan.', 'success');
        pulsaProviderForm.name = '';
        await loadPulsaProviders();
        await loadPulsaDaily();
      } catch (_) {
      } finally {
        loading.value = false;
      }
    }

    async function togglePulsaProvider(p) {
      if (!canInputPulsa.value) return;
      const next = p.status === 'active' ? 'inactive' : 'active';
      loading.value = true;
      try {
        await api(`/pulsa-profits/providers/${p.id}`, {
          method: 'PUT',
          body: JSON.stringify({ status: next }),
        });
        toast(next === 'active' ? 'Provider diaktifkan.' : 'Provider dinonaktifkan.', 'success');
        await loadPulsaProviders();
        await loadPulsaDaily();
      } catch (_) {
      } finally {
        loading.value = false;
      }
    }

    async function deletePulsaProvider(p) {
      if (!canInputPulsa.value) return;
      const ok = await askConfirm({ title: 'Hapus Provider', message: `Hapus / nonaktifkan provider ${p.name}?`, confirmLabel: 'Hapus', danger: true });
      if (!ok) return;
      loading.value = true;
      try {
        const data = await api(`/pulsa-profits/providers/${p.id}`, { method: 'DELETE' });
        toast(data.message || 'Provider dihapus.', 'success');
        await loadPulsaProviders();
        await loadPulsaDaily();
      } catch (_) {
      } finally {
        loading.value = false;
      }
    }

    async function submitPulsaDaily() {
      if (!canInputPulsa.value) {
        toast('Owner hanya dapat memantau. Input hanya Admin/PIC cabang konter.', 'error');
        return;
      }
      if (!pulsaBalances.value.length) {
        toast('Belum ada provider. Tambah provider dulu.', 'error');
        return;
      }
      for (let i = 0; i < pulsaBalances.value.length; i++) {
        const r = pulsaBalances.value[i];
        if (r.closing_balance === '' || r.closing_balance == null) {
          toast(`Isi saldo sekarang untuk ${r.provider_name}.`, 'error');
          return;
        }
      }
      const expenses = [];
      for (const r of pulsaExpenses.value) {
        const name = String(r.name || '').trim();
        const amount = parseInputNumber(r.amount);
        if (!name && !amount) continue;
        if (!name || amount <= 0) {
          toast('Lengkapi nama dan nominal pengeluaran, atau kosongkan baris.', 'error');
          return;
        }
        expenses.push({ name, amount });
      }
      const ok = await askClosingConfirm({
        title: 'Simpan Keuntungan Pulsa',
        message: `Simpan catatan pulsa tanggal ${pulsaDate.value}?`,
        detail: `Keuntungan ${formatRp(pulsaProfit.value)}`,
        confirmLabel: 'Simpan',
      });
      if (!ok) return;
      loading.value = true;
      try {
        const payload = {
          sheet_date: pulsaDate.value,
          cash_on_hand: parseInputNumber(pulsaForm.cash_on_hand),
          note: String(pulsaForm.note || '').trim() || null,
          balances: pulsaBalances.value.map((r) => ({
            pulsa_provider_id: Number(r.pulsa_provider_id),
            opening_balance: parseInputNumber(r.opening_balance),
            topup_amount: parseInputNumber(r.topup_amount),
            closing_balance: parseInputNumber(r.closing_balance),
          })),
          expenses,
        };
        const data = await api('/pulsa-profits/daily', {
          method: 'PUT',
          body: JSON.stringify(payload),
        });
        toast(data.message || 'Berhasil disimpan.', 'success');
        applyPulsaDaily(data.data || {});
        await loadPulsaHistory();
      } catch (_) {
      } finally {
        loading.value = false;
      }
    }

    async function deletePulsaSheet(row) {
      if (!canInputPulsa.value) return;
      const ok = await askConfirm({ title: 'Hapus Pulsa', message: `Hapus catatan pulsa ${row.sheet_date}?`, confirmLabel: 'Hapus', danger: true });
      if (!ok) return;
      loading.value = true;
      try {
        await api(`/pulsa-profits/${row.id}`, { method: 'DELETE' });
        toast('Catatan dihapus.', 'success');
        if (pulsaForm.id === row.id) await loadPulsaDaily();
        await loadPulsaHistory();
      } catch (_) {
      } finally {
        loading.value = false;
      }
    }

    function openPulsaHistoryRow(row) {
      pulsaDate.value = (row.sheet_date || '').toString().slice(0, 10);
      if (isOwner.value && row.branch_id) pulsaBranchId.value = row.branch_id;
      pulsaTab.value = 'daily';
      loadPulsaDaily();
    }

    function pulsaBranchLabel() {
      if (isOwner.value) {
        const b = (branches.value || []).find((x) => Number(x.id) === Number(pulsaBranchId.value));
        return b?.name || '—';
      }
      return user.value?.branch?.name
        || user.value?.employee?.branch?.name
        || '—';
    }

    function buildPulsaWhatsAppText() {
      const lines = [
        '*Keuntungan Pulsa — BMS*',
        `Tanggal: ${formatTxDailyDateLabel(pulsaDate.value)}`,
        `Cabang: ${pulsaBranchLabel()}`,
        '',
        '*Saldo Provider*',
      ];
      const balances = pulsaBalances.value || [];
      if (!balances.length) {
        lines.push('_Belum ada provider._');
      } else {
        balances.forEach((r) => {
          const used = pulsaUsedOf(r);
          const topup = parseInputNumber(r.topup_amount);
          const topupPart = topup > 0 ? ` + tambah ${formatRp(topup)}` : '';
          lines.push(
            `• *${r.provider_name}*: ${formatRp(parseInputNumber(r.opening_balance))}`
            + ` → ${formatRp(parseInputNumber(r.closing_balance))}${topupPart}`
            + ` = terpakai ${formatRp(used)}`
          );
        });
      }
      lines.push('');
      lines.push(`Saldo terpotong: ${formatRp(pulsaTotalUsed.value)}`);
      lines.push(`Uang pulsa: ${formatRp(parseInputNumber(pulsaForm.cash_on_hand))}`);
      lines.push(`Pengeluaran: ${formatRp(pulsaTotalExpense.value)}`);
      lines.push(`Total uang: ${formatRp(pulsaTotalCash.value)}`);
      lines.push(`*Keuntungan: ${formatRp(pulsaProfit.value)}*`);

      const expenses = (pulsaExpenses.value || []).filter((r) => {
        const name = String(r.name || '').trim();
        return name && parseInputNumber(r.amount) > 0;
      });
      if (expenses.length) {
        lines.push('');
        lines.push('*Rincian Pengeluaran*');
        expenses.forEach((r, i) => {
          lines.push(`${i + 1}. ${String(r.name).trim()} — ${formatRp(parseInputNumber(r.amount))}`);
        });
      }
      if (String(pulsaForm.note || '').trim()) {
        lines.push('');
        lines.push(`Catatan: ${String(pulsaForm.note).trim()}`);
      }
      return lines.join('\n').trim();
    }

    function sharePulsaWhatsApp() {
      if (!pulsaBalances.value.length) {
        toast('Belum ada data saldo untuk dikirim.', 'error');
        return;
      }
      const text = buildPulsaWhatsAppText();
      const url = `https://api.whatsapp.com/send?text=${encodeURIComponent(text)}`;
      const win = window.open(url, '_blank', 'noopener,noreferrer');
      if (!win) {
        toast('Popup diblokir browser. Izinkan popup untuk membuka WhatsApp.', 'error');
      }
    }

    function applyBrilinkDaily(data) {
      brilinkForm.id = data.id || null;
      brilinkForm.previous_total = formatInputNumber(data.previous_total || 0);
      brilinkForm.note = data.note || '';
      brilinkForm.is_new = !!data.is_new;
      const lines = (data.lines || []).map((l) => blankBrilinkLine({
        name: l.name || '',
        amount: data.is_new && !l.amount ? '' : formatInputNumber(l.amount || 0),
      }));
      brilinkLines.value = lines.length ? lines : [blankBrilinkLine()];
    }

    async function loadBrilinkDaily() {
      if (!canAccessBrilink.value) return;
      if (isOwner.value && !brilinkBranchId.value) {
        brilinkLines.value = [blankBrilinkLine()];
        brilinkForm.id = null;
        brilinkForm.is_new = true;
        return;
      }
      const params = new URLSearchParams({ date: brilinkDate.value });
      if (isOwner.value) params.set('branch_id', brilinkBranchId.value);
      loading.value = true;
      try {
        const data = await api(`/brilink/daily?${params.toString()}`);
        applyBrilinkDaily(data.data || {});
      } catch (_) {
        brilinkLines.value = [blankBrilinkLine()];
      } finally {
        loading.value = false;
      }
    }

    async function loadBrilinkHistory() {
      if (!canAccessBrilink.value) return;
      const params = new URLSearchParams();
      if (isOwner.value && brilinkBranchId.value) params.set('branch_id', brilinkBranchId.value);
      try {
        const data = await api(`/brilink?${params.toString()}`);
        brilinkHistory.value = data.data || [];
      } catch (_) {
        brilinkHistory.value = [];
      }
    }

    async function loadBrilinkPage() {
      if (!canAccessBrilink.value) return;
      if (isOwner.value && !brilinkBranchId.value && (branches.value || []).length) {
        brilinkBranchId.value = branches.value[0].id;
      }
      await loadBrilinkDaily();
      await loadBrilinkHistory();
    }

    async function copyBrilinkFromPrevious() {
      if (!canInputBrilink.value) return;
      if (isOwner.value && !brilinkBranchId.value) {
        toast('Pilih cabang dulu.', 'error');
        return;
      }
      loading.value = true;
      try {
        const payload = { date: brilinkDate.value };
        if (isOwner.value) payload.branch_id = Number(brilinkBranchId.value);
        const data = await api('/brilink/daily/copy-previous', {
          method: 'POST',
          body: JSON.stringify(payload),
        });
        const lines = (data.data?.lines || []).map((l) => blankBrilinkLine({
          name: l.name || '',
          amount: '',
        }));
        brilinkLines.value = lines.length ? lines : [blankBrilinkLine()];
        if (data.data?.previous_total != null) {
          brilinkForm.previous_total = formatInputNumber(data.data.previous_total);
        }
        toast(data.message || 'Nama item disalin.', 'success');
      } catch (_) {
      } finally {
        loading.value = false;
      }
    }

    async function submitBrilinkDaily() {
      if (!canInputBrilink.value) {
        toast('Owner hanya dapat memantau. Input hanya Admin/PIC cabang.', 'error');
        return;
      }
      const lines = [];
      for (let i = 0; i < brilinkLines.value.length; i++) {
        const r = brilinkLines.value[i];
        const name = String(r.name || '').trim();
        const amount = parseInputNumber(r.amount);
        if (!name && !amount) continue;
        if (!name) {
          toast(`Isi nama item pada baris ${i + 1}.`, 'error');
          return;
        }
        lines.push({ name, amount });
      }
      if (!lines.length) {
        toast('Isi minimal satu baris item.', 'error');
        return;
      }
      const ok = await askClosingConfirm({
        title: 'Simpan Brilink',
        message: `Simpan catatan Brilink tanggal ${brilinkDate.value}?`,
        detail: `Total ${formatRp(brilinkTotal.value)} · Keuntungan ${formatRp(brilinkProfit.value)}`,
        confirmLabel: 'Simpan',
      });
      if (!ok) return;
      loading.value = true;
      try {
        const data = await api('/brilink/daily', {
          method: 'PUT',
          body: JSON.stringify({
            sheet_date: brilinkDate.value,
            previous_total: parseInputNumber(brilinkForm.previous_total),
            note: String(brilinkForm.note || '').trim() || null,
            lines,
          }),
        });
        toast(data.message || 'Berhasil disimpan.', 'success');
        applyBrilinkDaily(data.data || {});
        await loadBrilinkHistory();
      } catch (_) {
      } finally {
        loading.value = false;
      }
    }

    async function deleteBrilinkSheet(row) {
      if (!canInputBrilink.value) return;
      const ok = await askConfirm({ title: 'Hapus Brilink', message: `Hapus catatan Brilink ${row.sheet_date}?`, confirmLabel: 'Hapus', danger: true });
      if (!ok) return;
      loading.value = true;
      try {
        await api(`/brilink/${row.id}`, { method: 'DELETE' });
        toast('Catatan dihapus.', 'success');
        if (brilinkForm.id === row.id) await loadBrilinkDaily();
        await loadBrilinkHistory();
      } catch (_) {
      } finally {
        loading.value = false;
      }
    }

    function openBrilinkHistoryRow(row) {
      brilinkDate.value = (row.sheet_date || '').toString().slice(0, 10);
      if (isOwner.value && row.branch_id) brilinkBranchId.value = row.branch_id;
      loadBrilinkDaily();
    }

    function brilinkBranchLabel() {
      if (isOwner.value) {
        const b = (branches.value || []).find((x) => Number(x.id) === Number(brilinkBranchId.value));
        return b?.name || '—';
      }
      return user.value?.branch?.name
        || user.value?.employee?.branch?.name
        || '—';
    }

    function buildBrilinkWhatsAppText() {
      const lines = [
        '*Brilink — BMS*',
        `Tanggal: ${formatTxDailyDateLabel(brilinkDate.value)}`,
        `Cabang: ${brilinkBranchLabel()}`,
        '',
      ];
      const items = (brilinkLines.value || []).filter((r) => String(r.name || '').trim());
      if (!items.length) {
        lines.push('_Belum ada item._');
      } else {
        items.forEach((r) => {
          lines.push(`• *${String(r.name).trim()}*: ${formatRp(parseInputNumber(r.amount))}`);
        });
      }
      lines.push('────────');
      lines.push(`*Total: ${formatRp(brilinkTotal.value)}*`);
      lines.push(`Saldo kemarin: ${formatRp(parseInputNumber(brilinkForm.previous_total))}`);
      lines.push(`*Keuntungan: ${formatRp(brilinkProfit.value)}*`);
      if (String(brilinkForm.note || '').trim()) {
        lines.push('');
        lines.push(`Catatan: ${String(brilinkForm.note).trim()}`);
      }
      return lines.join('\n').trim();
    }

    function shareBrilinkWhatsApp() {
      const filled = (brilinkLines.value || []).some((r) => String(r.name || '').trim());
      if (!filled) {
        toast('Belum ada item untuk dikirim.', 'error');
        return;
      }
      const text = buildBrilinkWhatsAppText();
      const url = `https://api.whatsapp.com/send?text=${encodeURIComponent(text)}`;
      const win = window.open(url, '_blank', 'noopener,noreferrer');
      if (!win) {
        toast('Popup diblokir browser. Izinkan popup untuk membuka WhatsApp.', 'error');
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

    async function submitTxDraftBatch() {
      if (periodLocked.value) {
        toast('Aksi Ditolak: Periode pembukuan telah dikunci oleh Owner.', 'error');
        return;
      }
      if (!txForm.transaction_date) {
        toast('Pilih tanggal transaksi dulu.', 'error');
        return;
      }
      if (isOwner.value && !txForm.branch_id) {
        toast('Pilih cabang dulu.', 'error');
        return;
      }

      const items = [];
      for (let i = 0; i < txDraftRows.value.length; i++) {
        const r = txDraftRows.value[i];
        const amount = parseInputNumber(r.amount);
        const hasAny = r.category_id || amount || String(r.description || '').trim() || r.id;
        if (!hasAny) continue;
        if (!r.category_id || !r.account_id || !amount) {
          toast(`Lengkapi baris ${i + 1}: tipe, kategori, akun, dan nominal.`, 'error');
          return;
        }
        items.push({
          id: r.id ? Number(r.id) : null,
          category_id: Number(r.category_id),
          account_id: Number(r.account_id),
          amount,
          description: String(r.description || '').trim() || null,
        });
      }

      if (txDayEdit.active) {
        await submitTxDayEditSync(items);
        return;
      }

      if (!items.length) {
        toast('Isi minimal satu baris transaksi.', 'error');
        return;
      }

      const ok = await askClosingConfirm({
        title: 'Simpan Multi Transaksi',
        message: `Simpan ${items.length} transaksi tanggal ${txForm.transaction_date}?`,
        detail: `Total nominal ${formatRp(items.reduce((s, it) => s + it.amount, 0))}`,
        confirmLabel: 'Simpan semua',
      });
      if (!ok) return;

      loading.value = true;
      try {
        const payload = {
          transaction_date: txForm.transaction_date,
          items: items.map(({ category_id, account_id, amount, description }) => ({
            category_id,
            account_id,
            amount,
            description,
          })),
        };
        if (isOwner.value) payload.branch_id = Number(txForm.branch_id);
        const data = await api('/transactions/batch', {
          method: 'POST',
          body: JSON.stringify(payload),
        });
        toast(data.message || 'Transaksi berhasil dicatat.', 'success');
        resetTxDraftRows();
        await refreshCurrent();
      } catch (_) {
        // api() sudah toast
      } finally {
        loading.value = false;
      }
    }

    async function submitTxDayEditSync(items) {
      const date = txDayEdit.date || txForm.transaction_date;
      const keptIds = new Set(items.filter((it) => it.id).map((it) => Number(it.id)));
      const toDelete = (txDayEdit.originalIds || []).filter((id) => !keptIds.has(Number(id)));

      if (!items.length && !toDelete.length) {
        toast('Tidak ada perubahan untuk disimpan.', 'error');
        return;
      }

      const detailParts = [];
      const updateCount = items.filter((it) => it.id).length;
      const createCount = items.filter((it) => !it.id).length;
      if (updateCount) detailParts.push(`${updateCount} diubah`);
      if (createCount) detailParts.push(`${createCount} baru`);
      if (toDelete.length) detailParts.push(`${toDelete.length} dihapus`);

      const ok = await askClosingConfirm({
        title: 'Simpan Edit Harian',
        message: items.length
          ? `Simpan ${items.length} baris transaksi tanggal ${date}?`
          : `Hapus semua transaksi tanggal ${date}?`,
        detail: detailParts.join(' · ') || `Total nominal ${formatRp(items.reduce((s, it) => s + it.amount, 0))}`,
        confirmLabel: 'Simpan perubahan',
        danger: !items.length && toDelete.length > 0,
      });
      if (!ok) return;

      loading.value = true;
      try {
        for (const id of toDelete) {
          await api(`/transactions/${id}`, { method: 'DELETE' });
        }
        for (const it of items) {
          if (it.id) {
            await api(`/transactions/${it.id}`, {
              method: 'PUT',
              body: JSON.stringify({
                category_id: it.category_id,
                account_id: it.account_id,
                amount: it.amount,
                transaction_date: date,
                description: it.description,
              }),
            });
          } else {
            const payload = {
              category_id: it.category_id,
              account_id: it.account_id,
              amount: it.amount,
              transaction_date: date,
              description: it.description,
            };
            if (isOwner.value) {
              payload.branch_id = Number(txDayEdit.branch_id || txForm.branch_id);
            }
            await api('/transactions', {
              method: 'POST',
              body: JSON.stringify(payload),
            });
          }
        }
        toast('Perubahan transaksi harian berhasil disimpan.', 'success');
        resetTxDraftRows();
        await refreshCurrent();
      } catch (_) {
        // api() sudah toast
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

    async function loadDbBackups() {
      if (!isOwner.value) return;
      try {
        const data = await api('/system/database-backups');
        dbBackupList.value = data.data || [];
        dbBackupMeta.keep = data.meta?.keep ?? 10;
        dbBackupMeta.note = data.meta?.note || '';
        dbBackupMeta.confirm_phrase = data.meta?.confirm_phrase || 'PULIHKAN';
        dbBackupMeta.upload_max_kb = data.meta?.upload_max_kb ?? 20480;
        const sch = data.meta?.schedule || {};
        dbBackupMeta.schedule = {
          enabled: sch.enabled !== false,
          time: sch.time || '02:00',
          timezone: sch.timezone || 'Asia/Jayapura',
          last_run_at: sch.last_run_at || null,
          last_status: sch.last_status || null,
          last_filename: sch.last_filename || null,
          last_error: sch.last_error || null,
          next_run_at: sch.next_run_at || null,
        };
        dbBackupForm.scheduleEnabled = dbBackupMeta.schedule.enabled;
        dbBackupForm.scheduleTime = dbBackupMeta.schedule.time;
      } catch (_) {
        dbBackupList.value = [];
      }
    }

    async function saveDbBackupSchedule() {
      if (!isOwner.value) return;
      const time = String(dbBackupForm.scheduleTime || '').trim();
      if (!/^([01]?\d|2[0-3]):([0-5]\d)$/.test(time)) {
        toast('Format jam tidak valid. Contoh: 02:00', 'error');
        return;
      }
      dbBackupForm.scheduleSaving = true;
      loading.value = true;
      try {
        const data = await api('/system/database-backups/schedule', {
          method: 'PUT',
          body: JSON.stringify({
            enabled: !!dbBackupForm.scheduleEnabled,
            time,
          }),
        });
        toast(data.message || 'Jadwal backup disimpan.', 'success');
        const sch = data.data || {};
        dbBackupMeta.schedule = {
          enabled: sch.enabled !== false,
          time: sch.time || time,
          timezone: sch.timezone || 'Asia/Jayapura',
          last_run_at: sch.last_run_at || null,
          last_status: sch.last_status || null,
          last_filename: sch.last_filename || null,
          last_error: sch.last_error || null,
          next_run_at: sch.next_run_at || null,
        };
        dbBackupForm.scheduleEnabled = dbBackupMeta.schedule.enabled;
        dbBackupForm.scheduleTime = dbBackupMeta.schedule.time;
      } catch (e) {
        if (!e?.status) toast(e?.message || 'Gagal menyimpan jadwal.', 'error');
      } finally {
        dbBackupForm.scheduleSaving = false;
        loading.value = false;
      }
    }

    async function createDbBackup() {
      if (!isOwner.value) return;
      if (!dbBackupForm.password) {
        toast('Masukkan kata sandi Owner untuk membuat backup.', 'error');
        return;
      }
      const ok = await askConfirm({
        title: 'Buat Backup Database',
        message: 'Buat dump database sekarang dan unduh ke perangkat Anda?',
        detail: 'File berisi seluruh data BMS. Simpan di tempat aman.',
        confirmLabel: 'Buat & Unduh',
      });
      if (!ok) return;

      dbBackupForm.creating = true;
      loading.value = true;
      try {
        const data = await api('/system/database-backups', {
          method: 'POST',
          body: JSON.stringify({ password: dbBackupForm.password }),
        });
        dbBackupForm.password = '';
        toast(data.message || 'Backup berhasil dibuat.', 'success');
        const filename = data.data?.filename || 'bms-backup.dump';
        await triggerDbBackupDownload(filename);
        await loadDbBackups();
      } catch (e) {
        if (!e?.status) toast(e?.message || 'Gagal membuat backup.', 'error');
      } finally {
        dbBackupForm.creating = false;
        loading.value = false;
      }
    }

    async function downloadDbBackup(row) {
      if (!row?.filename) return;
      loading.value = true;
      try {
        await triggerDbBackupDownload(row.filename);
      } catch (e) {
        if (!e?.status) toast(e?.message || 'Gagal mengunduh backup.', 'error');
      } finally {
        loading.value = false;
      }
    }

    async function triggerDbBackupDownload(filename) {
      // Unduh lewat API Bearer (same-origin) — hindari signed URL lintas host → Failed to fetch.
      const name = filename || 'bms-backup.dump';
      try {
        const headers = { Accept: 'application/octet-stream' };
        if (token.value) headers.Authorization = `Bearer ${token.value}`;
        const res = await fetch(
          `${API_BASE}/system/database-backups/${encodeURIComponent(name)}/download`,
          { method: 'GET', headers, credentials: 'same-origin', cache: 'no-store' }
        );
        if (!res.ok) {
          let msg = 'Gagal mengunduh file backup.';
          try {
            const err = await res.json();
            if (err?.message) msg = err.message;
          } catch (_) {}
          toast(msg, 'error');
          return;
        }
        const buf = await res.arrayBuffer();
        if (!buf || buf.byteLength < 64) {
          toast('File backup kosong atau rusak.', 'error');
          return;
        }
        const blobUrl = URL.createObjectURL(new Blob([buf], { type: 'application/octet-stream' }));
        const a = document.createElement('a');
        a.href = blobUrl;
        a.download = name;
        a.rel = 'noopener';
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(() => URL.revokeObjectURL(blobUrl), 15_000);
        toast(`File tersimpan: ${name}`, 'success');
      } catch (e) {
        toast(e?.message || 'Gagal mengunduh file backup. Coba hard-refresh lalu ulangi.', 'error');
      }
    }

    function openDbRestore(row) {
      if (!row?.filename) return;
      dbRestoreModal.open = true;
      dbRestoreModal.filename = row.filename;
      dbRestoreModal.password = '';
      dbRestoreModal.confirm_phrase = '';
      dbRestoreModal.restoring = false;
    }

    function closeDbRestore() {
      if (dbRestoreModal.restoring) return;
      dbRestoreModal.open = false;
      dbRestoreModal.filename = '';
      dbRestoreModal.password = '';
      dbRestoreModal.confirm_phrase = '';
    }

    async function confirmDbRestore() {
      if (!dbRestoreModal.filename) return;
      if (dbRestoreModal.restoring) return;
      if (!dbRestoreModal.password) {
        toast('Masukkan kata sandi Owner.', 'error');
        return;
      }
      const phrase = dbBackupMeta.confirm_phrase || 'PULIHKAN';
      if (String(dbRestoreModal.confirm_phrase || '').trim() !== phrase) {
        toast(`Ketik ${phrase} untuk mengonfirmasi.`, 'error');
        return;
      }

      // Langsung restore (sandi + frasa sudah konfirmasi). Jangan buka modal kedua (tertutup di belakang).
      dbRestoreModal.restoring = true;
      loading.value = true;
      toast('Memulihkan database…', 'info');
      try {
        const data = await api(`/system/database-backups/${encodeURIComponent(dbRestoreModal.filename)}/restore`, {
          method: 'POST',
          body: JSON.stringify({
            password: dbRestoreModal.password,
            confirm_phrase: dbRestoreModal.confirm_phrase,
          }),
        });
        toast(data.message || 'Database berhasil dipulihkan.', 'success');
        dbRestoreModal.open = false;
        dbRestoreModal.password = '';
        dbRestoreModal.confirm_phrase = '';
        await loadDbBackups();
      } catch (e) {
        toast(e?.message || 'Gagal memulihkan database.', 'error');
      } finally {
        dbRestoreModal.restoring = false;
        loading.value = false;
      }
    }

    function onDbBackupFileChange(ev) {
      const f = ev?.target?.files?.[0] || null;
      dbBackupForm.uploadFile = f;
    }

    async function uploadDbBackup() {
      if (!isOwner.value) return;
      if (!dbBackupForm.password) {
        toast('Masukkan kata sandi Owner untuk mengunggah backup.', 'error');
        return;
      }
      if (!dbBackupForm.uploadFile) {
        toast('Pilih file .dump terlebih dahulu.', 'error');
        return;
      }

      const ok = await askConfirm({
        title: 'Unggah File Backup',
        message: `Unggah ${dbBackupForm.uploadFile.name} ke server?`,
        detail: 'File harus format pg_dump -Fc (.dump). Setelah terunggah, Anda bisa menekan Pulihkan.',
        confirmLabel: 'Unggah',
      });
      if (!ok) return;

      dbBackupForm.uploading = true;
      loading.value = true;
      try {
        const fd = new FormData();
        fd.append('password', dbBackupForm.password);
        fd.append('file', dbBackupForm.uploadFile);

        const headers = { Accept: 'application/json' };
        if (token.value) headers.Authorization = `Bearer ${token.value}`;
        // Jangan set Content-Type manual — biarkan browser mengisi multipart boundary.
        const res = await fetch(`${API_BASE}/system/database-backups/upload`, {
          method: 'POST',
          headers,
          body: fd,
        });
        let data = null;
        try {
          data = await res.json();
        } catch (_) {
          data = null;
        }
        if (!res.ok) {
          const msg =
            data?.message ||
            (data?.errors && Object.values(data.errors).flat()[0]) ||
            'Gagal mengunggah backup.';
          toast(msg, 'error');
          return;
        }
        dbBackupForm.password = '';
        dbBackupForm.uploadFile = null;
        const input = document.getElementById('db-backup-upload-input');
        if (input) input.value = '';
        toast(data.message || 'File backup berhasil diunggah.', 'success');
        await loadDbBackups();
      } catch (e) {
        toast(e?.message || 'Gagal mengunggah backup.', 'error');
      } finally {
        dbBackupForm.uploading = false;
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
      const date = (t.transaction_date || '').toString().slice(0, 10);
      const branchId = t.branch_id || t.branch?.id || '';
      if (date) txForm.transaction_date = date;
      if (isOwner.value && branchId) txForm.branch_id = branchId;

      // Isi form edit + sinkron tanggal/cabang di form utama.
      editTxModal.open = true;
      editTxModal.id = t.id;
      editTxModal.type = t.category?.type || 'income';
      editTxModal.category_id = t.category_id || t.category?.id || '';
      editTxModal.account_id = t.account_id || t.account?.id || '';
      editTxModal.amount = formatInputNumber(Math.round(Number(t.amount) || 0));
      editTxModal.transaction_date = date;
      editTxModal.description = t.description || '';

      const formEl = document.getElementById('tx-form-card');
      if (formEl) formEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    async function openEditTxDay(group) {
      if (periodLocked.value) {
        toast('Aksi Ditolak: Periode pembukuan telah dikunci oleh Owner.', 'error');
        return;
      }
      if (!group?.date) return;

      loading.value = true;
      try {
        const rows = await fetchTxDayRows(group.date, group.branch_id || '');
        if (!rows.length) {
          toast('Tidak ada transaksi pada tanggal ini.', 'error');
          return;
        }

        const sorted = rows
          .slice()
          .sort((a, b) => Number(a.id) - Number(b.id));

        txForm.transaction_date = group.date;
        if (isOwner.value && group.branch_id) {
          txForm.branch_id = group.branch_id;
        }

        txDraftRows.value = sorted.map((t) => blankTxDraftRow({
          id: t.id,
          type: t.category?.type || 'income',
          category_id: t.category_id || t.category?.id || '',
          account_id: t.account_id || t.account?.id || defaultTxAccountId(),
          amount: formatInputNumber(Math.round(Number(t.amount) || 0)),
          description: t.description || '',
        }));

        txDayEdit.active = true;
        txDayEdit.date = group.date;
        txDayEdit.branch_id = group.branch_id || '';
        txDayEdit.originalIds = sorted.map((t) => Number(t.id));
        scheduleRefreshTxFisikSystemBase();

        const formEl = document.getElementById('tx-form-card');
        if (formEl) formEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
        toast(`${sorted.length} transaksi tanggal ${formatTxDailyDateLabel(group.date)} dimuat ke form.`, 'success');
      } catch (_) {
        // api() sudah toast
      } finally {
        loading.value = false;
      }
    }

    function cancelTxDayEdit() {
      resetTxDraftRows();
      toast('Mode edit harian dibatalkan.', 'success');
    }

    function openTxGroupDetail(group) {
      if (!group?.date) return;
      openTxDailyReport({
        date: group.date,
        branch_id: group.branch_id || '',
        branch_name: group.branch_name || '',
      });
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
      {
        const okDel = await askConfirm({
          title: 'Hapus Transaksi',
          message: 'Hapus transaksi ini?',
          detail: 'Tindakan ini tidak dapat dibatalkan.',
          confirmLabel: 'Hapus',
          danger: true,
        });
        if (!okDel) return;
      }
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

    function accountNameById(id) {
      const acc = accounts.value.find((a) => Number(a.id) === Number(id));
      return acc?.name || '—';
    }

    const internalTransferPreview = computed(() => {
      const amount = parseInputNumber(internalTransferForm.amount);
      const fromId = internalTransferForm.from_account_id;
      const toId = internalTransferForm.to_account_id;
      if (!fromId || !toId || !amount) return null;
      if (Number(fromId) === Number(toId)) return null;
      return {
        from: accountNameById(fromId),
        to: accountNameById(toId),
        amount,
        date: internalTransferForm.transaction_date,
      };
    });

    async function submitInternalTransfer() {
      if (periodLocked.value) {
        toast('Aksi Ditolak: Periode pembukuan telah dikunci oleh Owner.', 'error');
        return;
      }
      if (isOwner.value && !internalTransferForm.branch_id) {
        toast('Pilih cabang dulu.', 'error');
        return;
      }
      const amount = parseInputNumber(internalTransferForm.amount);
      if (!amount || !internalTransferForm.from_account_id || !internalTransferForm.to_account_id) {
        toast('Lengkapi akun asal, akun tujuan, dan nominal.', 'error');
        return;
      }
      if (Number(internalTransferForm.from_account_id) === Number(internalTransferForm.to_account_id)) {
        toast('Akun asal dan tujuan harus berbeda.', 'error');
        return;
      }
      const fromName = accountNameById(internalTransferForm.from_account_id);
      const toName = accountNameById(internalTransferForm.to_account_id);
      const ok = await askClosingConfirm({
        title: 'Simpan Transfer Antar Akun',
        message: `Pindahkan ${formatRp(amount)} dari ${fromName} ke ${toName}?`,
        detail: `Tanggal ${internalTransferForm.transaction_date}`,
        confirmLabel: 'Simpan Transfer',
      });
      if (!ok) return;
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

    async function loadAdjustmentReconAlerts() {
      if (!isOwner.value) return;
      adjustmentReconLoading.value = true;
      try {
        const params = new URLSearchParams();
        params.set('with_difference', '1');
        params.set('days', '60');
        params.set('limit', '30');
        const data = await api(`/reconciliations?${params.toString()}`);
        adjustmentReconAlerts.value = data.data || [];
      } catch (_) {
        adjustmentReconAlerts.value = [];
      } finally {
        adjustmentReconLoading.value = false;
      }
    }

    function clearAdjustmentForm(keepBranch = true) {
      const branchId = keepBranch ? adjustmentForm.branch_id : '';
      adjustmentForm.branch_id = branchId;
      adjustmentForm.account_id = '';
      adjustmentForm.type = 'income';
      adjustmentForm.amount = '';
      adjustmentForm.reason = '';
      adjustmentForm.transaction_date = today();
      adjustmentForm.reconciliation_id = null;
    }

    async function fillAdjustmentFromRecon(row) {
      if (!row) return;
      if (row.is_adjusted) {
        toast('Selisih ini sudah disesuaikan dan terkunci.', 'info');
        return;
      }
      const diff = Number(row.difference || 0);
      if (Math.abs(diff) < 0.01) {
        toast('Tidak ada selisih untuk diisi.', 'info');
        return;
      }
      const type = diff > 0 ? 'income' : 'expense';
      const amount = Math.abs(diff);
      adjustmentForm.reconciliation_id = row.id;
      adjustmentForm.branch_id = row.branch_id;
      adjustmentForm.type = type;
      adjustmentForm.amount = formatInputNumber(Math.round(amount));
      adjustmentForm.transaction_date = (row.reconciliation_date || today()).toString().slice(0, 10);
      adjustmentForm.reason = `Koreksi dari rekonsiliasi ${formatDate(row.reconciliation_date) || row.reconciliation_date}: fisik ${formatRp(row.physical_balance)}, sistem ${formatRp(row.system_balance)}, selisih ${formatRp(diff)}.`;
      try {
        await loadAccounts(row.branch_id);
      } catch (_) {}
      adjustmentForm.account_id = row.account_id;
      toast('Form diisi dari selisih. Periksa lalu simpan — selisih akan terkunci setelah disimpan.', 'success');
      const formEl = document.getElementById('adjustment-form-card');
      if (formEl) formEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    async function submitAdjustment() {
      const amount = parseInputNumber(adjustmentForm.amount);
      if (!adjustmentForm.branch_id || !adjustmentForm.account_id || !amount || !adjustmentForm.reason.trim()) {
        toast('Lengkapi cabang, akun, nominal, dan alasan.', 'error');
        return;
      }
      if (loading.value) return;
      loading.value = true;
      try {
        const payload = {
          branch_id: Number(adjustmentForm.branch_id),
          account_id: Number(adjustmentForm.account_id),
          type: adjustmentForm.type,
          amount,
          reason: adjustmentForm.reason.trim(),
          transaction_date: adjustmentForm.transaction_date,
        };
        if (adjustmentForm.reconciliation_id) {
          payload.reconciliation_id = Number(adjustmentForm.reconciliation_id);
        }
        const data = await api('/adjustments', {
          method: 'POST',
          body: JSON.stringify(payload),
        });
        toast(data.message || 'Penyesuaian saldo berhasil dicatat.', 'success');
        clearAdjustmentForm(true);
        if (adjustmentForm.branch_id) await loadAccounts(adjustmentForm.branch_id);
        await loadAdjustmentReconAlerts();
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
      const ok = await askConfirm({ title: 'Hapus Tipe Cabang', message: `Hapus tipe "${t.name}"?`, confirmLabel: 'Hapus', danger: true });
      if (!ok) return;
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
      const ok = await askConfirm({ title: 'Hapus Admin', message: `Hapus admin "${a.name}" (${a.email})?`, confirmLabel: 'Hapus', danger: true });
      if (!ok) return;
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
      const ok = await askConfirm({ title: 'Hapus Kategori', message: `Hapus kategori "${c.name}"?`, detail: 'Hanya bisa jika belum dipakai di transaksi.', confirmLabel: 'Hapus', danger: true });
      if (!ok) return;
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
      if (page.value === 'services') {
        ensureSvcDraftRows(3);
        await loadServiceRecords();
      }
      if (page.value === 'pulsa-profit') await loadPulsaProfitPage();
      if (page.value === 'brilink') await loadBrilinkPage();
      if (page.value === 'closings') await loadClosingBoard();
      if (page.value === 'attendance') {
        if (attendanceTab.value === 'daily') await loadAttendanceDaily();
        else await loadAttendanceBoard();
      }
      if (page.value === 'payroll') await loadPayrollBoard();
      if (page.value === 'profit-shares') await loadProfitShareBoard();
      if (page.value === 'workshop-wages') await loadWorkshopWagePage();
      if (page.value === 'workshop-upah-report' && canAccessWorkshopUpahReport.value) {
        await loadWorkshopUpahReport();
      }
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
        if (['dashboard', 'today-ops', 'transactions', 'internal-transfer', 'adjustments'].includes(page.value)) {
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
      if (page.value === 'adjustments' && isOwner.value) {
        await loadAdjustmentReconAlerts();
        if (adjustmentForm.branch_id) await loadAccounts(adjustmentForm.branch_id);
      }
      if (page.value === 'cashflow' && canAccessCashflow.value) await loadCashflowBoard();
      if (page.value === 'db-backup' && isOwner.value) await loadDbBackups();
      if (page.value === 'reports' && canAccessReports.value) {
        if (isAdmin.value && isWorkshopBranch.value && !['alur-kas', 'brilink', 'upah'].includes(reportForm.type)) {
          reportForm.type = 'alur-kas';
          reportForm.branch_id = '';
        }
        if (isAdmin.value && !isWorkshopBranch.value && ['gaji', 'bagi-hasil', 'upah'].includes(reportForm.type)) {
          reportForm.type = 'ringkasan';
        }
      }
      if (page.value === 'emp-home') await loadEmpToday();
      if (page.value === 'emp-history') await loadEmpHistory();
      if (page.value === 'emp-reviews') await loadAttReviews();
      if (page.value === 'emp-upah' && isPicWorkshop.value) await loadEmpUpahReport();
      if (page.value === 'emp-pulsa' && isPicCounter.value) await loadPulsaProfitPage();
      if (page.value === 'emp-brilink' && isPicEmployee.value) await loadBrilinkPage();
    }

    async function loadEmpUpahReport() {
      reportForm.type = 'upah';
      reportForm.branch_id = '';
      loading.value = true;
      try {
        const data = await api(`/reports/upah${buildReportQuery()}`);
        reportResult.value = data;
      } catch (_) {
        reportResult.value = null;
      } finally {
        loading.value = false;
      }
    }

    async function loadWorkshopUpahReport() {
      reportForm.type = 'upah';
      if (!isOwner.value) reportForm.branch_id = '';
      loading.value = true;
      try {
        await loadReportUpahTechnicians();
        const data = await api(`/reports/upah${buildReportQuery()}`);
        reportResult.value = data;
      } catch (_) {
        reportResult.value = null;
      } finally {
        loading.value = false;
      }
    }

    async function go(next) {
      if (isEmployeeRole.value) {
        const allowed = ['emp-home', 'emp-history', 'emp-reviews', 'profile'];
        if (isPicWorkshop.value) allowed.push('emp-upah');
        if (isPicCounter.value) allowed.push('emp-pulsa');
        if (isPicEmployee.value) allowed.push('emp-brilink');
        if (!allowed.includes(next)) next = 'emp-home';
      } else if (next === 'workshop-upah-report') {
        // Gabung ke Buat Laporan (jenis Upah Bengkel).
        if (!canAccessWorkshopUpahReport.value && !canAccessReports.value) {
          toast('Laporan upah hanya untuk cabang bengkel.', 'error');
          next = 'dashboard';
        } else {
          next = 'reports';
          reportForm.type = 'upah';
          if (!isOwner.value) reportForm.branch_id = '';
        }
      } else if (next === 'today-ops' && !canAccessTodayOps.value) {
        next = 'dashboard';
      } else if ((next === 'reports' && !canAccessReports.value) || (next === 'cashflow' && !canAccessCashflow.value)) {
        toast('Menu ini tidak tersedia untuk akun Anda.', 'error');
        next = 'dashboard';
      }
      if (next === 'reports' && isAdmin.value && isWorkshopBranch.value && !['alur-kas', 'brilink', 'upah'].includes(reportForm.type)) {
        reportForm.type = 'alur-kas';
        reportForm.branch_id = '';
      }
      if (next === 'reports' && isAdmin.value && !isWorkshopBranch.value && ['gaji', 'bagi-hasil', 'upah'].includes(reportForm.type)) {
        reportForm.type = 'ringkasan';
      }
      page.value = next;
      syncNavGroups(next);
      if (next === 'profile') fillProfileForm();
      await refreshCurrent();
      if (isOwner.value && next === 'dashboard') {
        await nextTick();
        renderOwnerCharts();
      }
    }

    async function compressImageFile(file, maxW = 960, quality = 0.72) {
      return new Promise((resolve, reject) => {
        const img = new Image();
        const url = URL.createObjectURL(file);
        img.onload = () => {
          try {
            const scale = Math.min(1, maxW / img.width);
            const canvas = document.createElement('canvas');
            canvas.width = Math.max(1, Math.round(img.width * scale));
            canvas.height = Math.max(1, Math.round(img.height * scale));
            canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
            URL.revokeObjectURL(url);
            resolve(canvas.toDataURL('image/jpeg', quality));
          } catch (err) {
            URL.revokeObjectURL(url);
            reject(err);
          }
        };
        img.onerror = () => {
          URL.revokeObjectURL(url);
          reject(new Error('Gagal membaca foto'));
        };
        img.src = url;
      });
    }

    async function onEmpPhotoPick(event) {
      const file = event.target.files && event.target.files[0];
      event.target.value = '';
      if (!file) return;
      try {
        empPhotoPreview.value = await compressImageFile(file);
      } catch (_) {
        toast('Gagal memproses foto.', 'error');
      }
    }

    async function loadEmpToday() {
      if (!isEmployeeRole.value) return;
      loading.value = true;
      try {
        const data = await api('/attendance/self/today');
        empToday.value = data.data || null;
      } catch (_) {
        empToday.value = null;
      } finally {
        loading.value = false;
      }
    }

    async function loadEmpHistory() {
      if (!isEmployeeRole.value) return;
      loading.value = true;
      try {
        const params = new URLSearchParams({
          year: String(empHistoryFilter.year),
          month: String(empHistoryFilter.month),
        });
        const data = await api(`/attendance/self/history?${params}`);
        empHistory.value = data.data?.rows || [];
      } catch (_) {
        empHistory.value = [];
      } finally {
        loading.value = false;
      }
    }

    async function submitEmpCheckIn() {
      if (!empPhotoPreview.value) {
        toast('Ambil foto masuk terlebih dahulu.', 'error');
        return;
      }
      loading.value = true;
      try {
        const data = await api('/attendance/self/check-in', {
          method: 'POST',
          body: JSON.stringify({ photo: empPhotoPreview.value }),
        });
        toast(data.message || 'Absen masuk berhasil.', 'success');
        empPhotoPreview.value = '';
        await loadEmpToday();
      } catch (_) {
        toast('Gagal absen masuk.', 'error');
      } finally {
        loading.value = false;
      }
    }

    async function submitEmpCheckOut() {
      if (!empPhotoPreview.value) {
        toast('Ambil foto pulang terlebih dahulu.', 'error');
        return;
      }
      loading.value = true;
      try {
        const data = await api('/attendance/self/check-out', {
          method: 'POST',
          body: JSON.stringify({ photo: empPhotoPreview.value }),
        });
        toast(data.message || 'Absen pulang berhasil.', 'success');
        empPhotoPreview.value = '';
        await loadEmpToday();
      } catch (_) {
        toast('Gagal absen pulang.', 'error');
      } finally {
        loading.value = false;
      }
    }

    async function submitEmpLeaveSick() {
      if (!String(empLeaveForm.note || '').trim()) {
        toast('Catatan wajib diisi untuk izin/sakit.', 'error');
        return;
      }
      loading.value = true;
      try {
        const data = await api('/attendance/self/leave-sick', {
          method: 'POST',
          body: JSON.stringify({
            status: empLeaveForm.status,
            note: String(empLeaveForm.note).trim(),
          }),
        });
        toast(data.message || 'Status dicatat.', 'success');
        empLeaveForm.note = '';
        await loadEmpToday();
      } catch (_) {
        toast('Gagal menyimpan izin/sakit.', 'error');
      } finally {
        loading.value = false;
      }
    }

    async function loadAttSettings() {
      if (!isOwner.value || !attSettingsForm.branch_id) return;
      loading.value = true;
      try {
        const data = await api(`/attendance/settings?branch_id=${attSettingsForm.branch_id}`);
        const d = data.data || {};
        attSettingsForm.check_in_start = d.check_in_start || '07:00';
        attSettingsForm.check_in_end = d.check_in_end || '09:00';
        attSettingsForm.check_out_start = d.check_out_start || '16:00';
        attSettingsForm.check_out_end = d.check_out_end || '20:00';
      } catch (_) {
        toast('Gagal memuat jam absensi.', 'error');
      } finally {
        loading.value = false;
      }
    }

    async function saveAttSettings() {
      if (!isOwner.value) return;
      loading.value = true;
      try {
        const data = await api('/attendance/settings', {
          method: 'PUT',
          body: JSON.stringify({ ...attSettingsForm }),
        });
        toast(data.message || 'Jam absensi disimpan.', 'success');
      } catch (_) {
        toast('Gagal menyimpan jam absensi.', 'error');
      } finally {
        loading.value = false;
      }
    }

    async function loadAttReviews() {
      if (!isOwner.value && !isPicEmployee.value) return;
      loading.value = true;
      try {
        const data = await api('/attendance/reviews');
        attReviewRows.value = data.data?.rows || [];
      } catch (_) {
        attReviewRows.value = [];
      } finally {
        loading.value = false;
      }
    }

    async function reviewAttendance(row, decision) {
      if (!row?.id) return;
      const note = decision === 'reject' ? (prompt('Catatan penolakan (opsional):') || '') : '';
      loading.value = true;
      try {
        const data = await api(`/attendance/reviews/${row.id}`, {
          method: 'POST',
          body: JSON.stringify({ decision, note: note || null }),
        });
        toast(data.message || 'Tinjauan disimpan.', 'success');
        await loadAttReviews();
      } catch (_) {
        toast('Gagal meninjau absensi.', 'error');
      } finally {
        loading.value = false;
      }
    }

    function openEmpAccountModal(emp) {
      empAccountModal.open = true;
      empAccountModal.employee_id = emp.id;
      empAccountModal.employee_name = emp.name;
      empAccountModal.email = emp.user_account?.email || '';
      empAccountModal.password = '';
    }

    async function submitEmpAccount() {
      if (!empAccountModal.employee_id) return;
      loading.value = true;
      try {
        const payload = {
          email: empAccountModal.email,
          name: empAccountModal.employee_name,
        };
        if (empAccountModal.password) payload.password = empAccountModal.password;
        const data = await api(`/employees/${empAccountModal.employee_id}/account`, {
          method: 'PUT',
          body: JSON.stringify(payload),
        });
        toast(data.message || 'Akun login disimpan.', 'success');
        empAccountModal.open = false;
        await loadEmployees();
      } catch (_) {
        toast('Gagal menyimpan akun login.', 'error');
      } finally {
        loading.value = false;
      }
    }

    async function bootstrapApp() {
      bootLoading.value = true;
      try {
        const me = await api('/auth/me');
        user.value = me.data;
        localStorage.setItem(USER_KEY, JSON.stringify(me.data));
        if (me.data?.role === 'employee') {
          page.value = 'emp-home';
          await loadEmpToday();
          return;
        }
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
          scheduleRefreshTxFisikSystemBase();
        }
      }
    );

    watch(
      () => [page.value, txForm.transaction_date],
      ([p]) => {
        if (p === 'transactions') scheduleRefreshTxFisikSystemBase();
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
      bindPwaInstallEvents();
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
      reportUpahTechnicians,
      reportResult,
      reportAlurDetail,
      loadReportUpahTechnicians,
      onReportUpahBranchChange,
      openReportAlurDetail,
      closeReportAlurDetail,
      reportRingkasanDetail,
      openReportRingkasanDetail,
      closeReportRingkasanDetail,
      reportUpahTechDetail,
      reportUpahTechDetailPagedRows,
      reportUpahTechDetailPageCount,
      openReportUpahTechDetail,
      closeReportUpahTechDetail,
      setReportUpahTechDetailPage,
      cashflowFilter,
      cashflowBoard,
      cashflowYears,
      cashflowMonthNames,
      cashflowMonthLabel,
      cashflowExpanded,
      cfEditor,
      cfIncomeLines,
      cfExpenseLines,
      isCashflowBranchExpanded,
      toggleCashflowBranch,
      amountClass,
      reportTechLabel,
      loadCashflowBoard,
      onCashflowFilterChange,
      openCfEditor,
      closeCfEditor,
      addCfLine,
      removeCfLine,
      onCfLineAmountInput,
      recalcCfEditor,
      saveCashflowWorkbook,
      seedCashflowFromSystem,
      copyCashflowPrevious,
      exportCashflowPdf,
      txForm,
      txDailyReport,
      openTxDailyReport,
      closeTxDailyReport,
      shareTxDailyWhatsApp,
      formatTxDailyDateLabel,
      txDraftRows,
      txDraftFilled,
      txDraftIncome,
      txDraftExpense,
      txDraftNet,
      txDraftTotal,
      txDraftSalesCash,
      txDraftHpSalesCash,
      txDraftHpSalesDisplay,
      onTxHpSalesInput,
      txDraftPulsaSalesCash,
      txDraftCashIncome,
      txDraftCashExpense,
      txDraftCashNet,
      txDraftBankExpense,
      txFisik,
      txFisikHasInput,
      txFisikSelisih,
      txFisikCocok,
      txFisikStatusLabel,
      txFisikStatusAmount,
      onTxFisikInput,
      txDailyCashSummary,
      txDayEdit,
      openEditTxDay,
      cancelTxDayEdit,
      txFilter,
      txMeta,
      transferForm,
      internalTransferForm,
      internalTransferPreview,
      adjustmentForm,
      branchForm,
      branchTypeForm,
      adminForm,
      categoryForm,
      admins,
      employees,
      kelolaTab,
      kelolaCategoryQuery,
      kelolaCategoryPage,
      kelolaCategoryPerPage,
      kelolaCategoriesFiltered,
      kelolaCategoriesPaged,
      kelolaCategoryPageCount,
      employeePositionOptions,
      employeeForm,
      employeeFilter,
      toggleEmployeePosition,
      formatEmployeePositions,
      employeesByBranch,
      serviceRecords,
      serviceSummary,
      serviceMeta,
      serviceTechnicians,
      serviceForm,
      svcDraftRows,
      svcDraftFilled,
      svcDraftTotalProfit,
      svcDraftProfit,
      addSvcDraftRow,
      removeSvcDraftRow,
      resetSvcDraftRows,
      submitSvcDraftBatch,
      serviceFilter,
      editTxModal,
      reconForm,
      lockForm,
      dbBackupList,
      dbBackupMeta,
      dbBackupForm,
      dbRestoreModal,
      loadDbBackups,
      createDbBackup,
      downloadDbBackup,
      saveDbBackupSchedule,
      openDbRestore,
      closeDbRestore,
      confirmDbRestore,
      onDbBackupFileChange,
      uploadDbBackup,
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
      formatPctShare,
      formatDate,
      formatDateTime,
      formatInputNumber,
      parseInputNumber,
      initialsOf,
      rowNo,
      doLogin,
      hardReloadApp,
      showPwaInstall,
      pwaCanInstall,
      pwaIsStandalone,
      installPwaApp,
      doLogout,
      profileForm,
      fillProfileForm,
      submitProfile,
      go,
      submitTransaction,
      submitTxDraftBatch,
      addTxDraftRow,
      removeTxDraftRow,
      resetTxDraftRows,
      onTxDraftTypeChange,
      categoriesForTxType,
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
      openTxGroupDetail,
      transactionGroups,
      submitEditTx,
      deleteTransaction,
      submitInternalTransfer,
      adjustmentReconAlerts,
      adjustmentReconLoading,
      loadAdjustmentReconAlerts,
      fillAdjustmentFromRecon,
      clearAdjustmentForm,
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
      canAccessProfitShares,
      canAccessReports,
      canAccessCashflow,
      canAccessTodayOps,
      canAccessKonterMenu,
      openReportFromDash,
      toggleOwnerDashExtra,
      ownerDashExtraOpen,
      askConfirm,
      canAccessWorkshopWages,
      canAccessWorkshopUpahReport,
      loadWorkshopUpahReport,
      isEmployeeRole,
      isPicEmployee,
      isPicWorkshop,
      isPicCounter,
      loadEmpUpahReport,
      empToday,
      empHistory,
      empHistoryFilter,
      empPhotoPreview,
      empLeaveForm,
      attSettingsForm,
      attReviewRows,
      empAccountModal,
      onEmpPhotoPick,
      loadEmpToday,
      loadEmpHistory,
      submitEmpCheckIn,
      submitEmpCheckOut,
      submitEmpLeaveSick,
      loadAttSettings,
      saveAttSettings,
      loadAttReviews,
      reviewAttendance,
      openEmpAccountModal,
      submitEmpAccount,
      workshopBranches,
      reportBranches,
      konterBranches,
      loadClosingBoard,
      onClosingFilterChange,
      closingIsLocked,
      closingGroupLocked,
      canEditClosingRow,
      lockClosingBoard,
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
      psFilter,
      psBoard,
      psEditor,
      psYears,
      psMonthLabel,
      psIncomeLines,
      psExpenseLines,
      loadProfitShareBoard,
      onPsFilterChange,
      openPsEditor,
      closePsEditor,
      addPsLine,
      removePsLine,
      onPsLineAmountInput,
      recalcPsEditor,
      saveProfitShare,
      lockProfitShare,
      unlockProfitShare,
      copyProfitSharePrevious,
      payrollDetail,
      payrollMonthLabel,
      loadPayrollBoard,
      onPayrollFilterChange,
      savePayrollBoard,
      lockPayrollBoard,
      unlockPayrollBoard,
      openPayrollDetail,
      closePayrollDetail,
      payrollDetailHas,
      openPayrollWhatsApp,
      openClosingWhatsApp,
      markPayrollPaid,
      markPayrollUnpaid,
      onPayrollManualInput,
      applyPayrollPicFromBagiHasil,
      onPayrollFocus,
      onPayrollKeydown,
      wwTab,
      wwDailyDate,
      wwJobs,
      wwTechnicians,
      wwJobTypes,
      wwJobTypeCatalog,
      wwJobTypeForm,
      wwWeeks,
      wwWeekDetail,
      wwMeta,
      wwFilter,
      wwYears,
      wwSettingsRows,
      wwSettingsMeta,
      wwJobForm,
      wwDraftRows,
      wwDraftFilled,
      wwDraftTotal,
      wwJobsDayTotal,
      loadWorkshopWagePage,
      switchWwTab,
      onWwFilterChange,
      loadWwDailyJobs,
      onWwDailyDateChange,
      resetWwJobForm,
      editWwJob,
      submitWwJob,
      submitWwDraftBatch,
      addWwDraftRow,
      removeWwDraftRow,
      resetWwDraftRows,
      applyWwJobTypeDefault,
      formatWwDateLabel,
      loadWwJobTypeCatalog,
      resetWwJobTypeForm,
      editWwJobType,
      submitWwJobType,
      toggleWwJobTypeStatus,
      deleteWwJobType,
      deleteWwJob,
      loadWwWeeksAndDetail,
      onWwWeekSelect,
      saveWwSettings,
      copyWwSettingsFromPrevious,
      wwPreviousMonthLabel,
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
      canAccessPulsa,
      canInputPulsa,
      pulsaTab,
      pulsaDate,
      pulsaBranchId,
      pulsaProviders,
      pulsaHistory,
      pulsaShowProviders,
      pulsaProviderForm,
      pulsaForm,
      pulsaBalances,
      pulsaExpenses,
      pulsaTotalUsed,
      pulsaTotalExpense,
      pulsaTotalCash,
      pulsaProfit,
      pulsaUsedOf,
      loadPulsaDaily,
      loadPulsaProfitPage,
      submitPulsaProvider,
      togglePulsaProvider,
      deletePulsaProvider,
      submitPulsaDaily,
      deletePulsaSheet,
      openPulsaHistoryRow,
      sharePulsaWhatsApp,
      canAccessBrilink,
      canInputBrilink,
      brilinkDate,
      brilinkBranchId,
      brilinkHistory,
      brilinkForm,
      brilinkLines,
      brilinkTotal,
      brilinkProfit,
      loadBrilinkDaily,
      loadBrilinkPage,
      copyBrilinkFromPrevious,
      submitBrilinkDaily,
      deleteBrilinkSheet,
      openBrilinkHistoryRow,
      shareBrilinkWhatsApp,
      addBrilinkLine() { brilinkLines.value.push(blankBrilinkLine()); },
      removeBrilinkLine(key) {
        if (brilinkLines.value.length <= 1) {
          brilinkLines.value = [blankBrilinkLine()];
          return;
        }
        brilinkLines.value = brilinkLines.value.filter((r) => r.key !== key);
      },
      addPulsaExpenseRow() { pulsaExpenses.value.push(blankPulsaExpense()); },
      removePulsaExpenseRow(key) {
        if (pulsaExpenses.value.length <= 1) {
          pulsaExpenses.value = [blankPulsaExpense()];
          return;
        }
        pulsaExpenses.value = pulsaExpenses.value.filter((r) => r.key !== key);
      },
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
          <button
            v-if="showPwaInstall"
            class="btn btn-ghost auth-submit"
            type="button"
            style="margin-top:10px"
            @click="installPwaApp"
          >Pasang ke Layar Utama</button>
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
    <div v-else-if="isEmployeeRole" class="emp-app">
      <header class="emp-header">
        <div class="emp-header-top">
          <div class="emp-brand">
            <span class="emp-logo">BMS</span>
            <div>
              <strong>{{ user?.name }}</strong>
              <small>{{ roleLabel }}</small>
            </div>
          </div>
          <div class="emp-header-actions">
            <button v-if="showPwaInstall" class="btn btn-ghost btn-sm" type="button" title="Pasang BMS ke layar utama" @click="installPwaApp">Pasang</button>
            <button class="btn btn-ghost btn-sm" type="button" title="Muat ulang aplikasi dari server" @click="hardReloadApp">Muat Ulang</button>
            <button class="btn btn-ghost btn-sm" type="button" @click="go('profile')">Akun</button>
            <button class="btn btn-ghost btn-sm" type="button" @click="doLogout">Keluar</button>
          </div>
        </div>
        <nav class="emp-tabs">
          <button type="button" class="emp-tab" :class="{active: page==='emp-home'}" @click="go('emp-home')">Absen</button>
          <button type="button" class="emp-tab" :class="{active: page==='emp-history'}" @click="go('emp-history')">Riwayat</button>
          <button v-if="isPicEmployee" type="button" class="emp-tab" :class="{active: page==='emp-reviews'}" @click="go('emp-reviews')">Tinjau</button>
          <button v-if="isPicWorkshop" type="button" class="emp-tab" :class="{active: page==='emp-upah'}" @click="go('emp-upah')">Upah</button>
          <button v-if="isPicCounter" type="button" class="emp-tab" :class="{active: page==='emp-pulsa'}" @click="go('emp-pulsa')">Pulsa</button>
          <button v-if="isPicEmployee" type="button" class="emp-tab" :class="{active: page==='emp-brilink'}" @click="go('emp-brilink')">Brilink</button>
        </nav>
      </header>

      <main class="emp-main">
        <section v-if="page==='emp-home'" class="card emp-card">
          <h2 class="brand">Absen Hari Ini</h2>
          <p class="filter-meta" v-if="empToday">
            {{ empToday.date }} · {{ empToday.employee?.branch_name }}
            · Masuk {{ empToday.window?.check_in_start }}–{{ empToday.window?.check_in_end }}
            · Pulang {{ empToday.window?.check_out_start }}–{{ empToday.window?.check_out_end }}
          </p>
          <div class="report-kpi-row" style="margin:12px 0" v-if="empToday?.attendance">
            <div class="report-kpi">
              <span class="report-kpi-label">Status</span>
              <strong>{{ empToday.attendance.status_label || empToday.attendance.self_state || 'Belum absen' }}</strong>
            </div>
            <div class="report-kpi">
              <span class="report-kpi-label">Masuk</span>
              <strong>{{ empToday.attendance.check_in_at ? formatDateTime(empToday.attendance.check_in_at) : '—' }}</strong>
            </div>
            <div class="report-kpi">
              <span class="report-kpi-label">Pulang</span>
              <strong>{{ empToday.attendance.check_out_at ? formatDateTime(empToday.attendance.check_out_at) : '—' }}</strong>
            </div>
          </div>

          <template v-if="!empToday?.attendance?.status || empToday.attendance.self_state==='checked_in'">
            <div class="field" style="margin-top:12px">
              <label>Foto absen (wajib untuk masuk/pulang)</label>
              <input type="file" accept="image/*" capture="user" @change="onEmpPhotoPick" />
              <img v-if="empPhotoPreview" :src="empPhotoPreview" alt="Preview" style="margin-top:8px;max-width:100%;border-radius:8px" />
            </div>
            <div class="att-actions" style="margin-top:12px;flex-wrap:wrap">
              <button
                class="btn btn-primary"
                type="button"
                :disabled="loading || !!empToday?.attendance?.check_in_at"
                @click="submitEmpCheckIn"
              >Absen Masuk</button>
              <button
                class="btn btn-primary"
                type="button"
                :disabled="loading || !empToday?.attendance?.check_in_at || !!empToday?.attendance?.check_out_at"
                @click="submitEmpCheckOut"
              >Absen Pulang</button>
            </div>
          </template>

          <div class="card" style="margin-top:16px;padding:12px">
            <div class="panel-title">Izin / Sakit</div>
            <p class="filter-meta">Tanpa foto. Wajib catatan.</p>
            <div class="field">
              <label>Status</label>
              <select v-model="empLeaveForm.status" :disabled="!!empToday?.attendance?.check_in_at">
                <option value="leave">Izin</option>
                <option value="sick">Sakit</option>
              </select>
            </div>
            <div class="field">
              <label>Catatan</label>
              <textarea v-model="empLeaveForm.note" rows="2" :disabled="!!empToday?.attendance?.check_in_at"></textarea>
            </div>
            <button class="btn btn-ghost" type="button" :disabled="loading || !!empToday?.attendance?.check_in_at" @click="submitEmpLeaveSick">Simpan Izin/Sakit</button>
          </div>
        </section>

        <section v-if="page==='emp-history'" class="card emp-card">
          <h2 class="brand">Riwayat Absensi</h2>
          <div class="filter-bar">
            <div class="field">
              <label>Bulan</label>
              <select v-model.number="empHistoryFilter.month" @change="loadEmpHistory">
                <option v-for="m in 12" :key="m" :value="m">{{ m }}</option>
              </select>
            </div>
            <div class="field">
              <label>Tahun</label>
              <select v-model.number="empHistoryFilter.year" @change="loadEmpHistory">
                <option v-for="y in [empHistoryFilter.year-1, empHistoryFilter.year, empHistoryFilter.year+1]" :key="y" :value="y">{{ y }}</option>
              </select>
            </div>
          </div>
          <div class="table-wrap">
            <table>
              <thead><tr><th>Tanggal</th><th>Status</th><th>Masuk</th><th>Pulang</th><th>Ket</th></tr></thead>
              <tbody>
                <tr v-for="r in empHistory" :key="r.id || r.date">
                  <td>{{ r.date }}</td>
                  <td>{{ r.status_label || r.self_state || '—' }}</td>
                  <td>{{ r.check_in_at ? formatDateTime(r.check_in_at) : '—' }}</td>
                  <td>{{ r.check_out_at ? formatDateTime(r.check_out_at) : '—' }}</td>
                  <td>{{ r.note || r.review_note || '—' }}</td>
                </tr>
                <tr v-if="!empHistory.length"><td colspan="5">Belum ada data.</td></tr>
              </tbody>
            </table>
          </div>
        </section>

        <section v-if="page==='emp-reviews' && isPicEmployee" class="card emp-card">
          <h2 class="brand">Tinjau Absen Tidak Lengkap</h2>
          <p class="filter-meta">Hanya cabang Anda. Setujui = Hadir; Tolak = Alpha.</p>
          <button class="btn btn-ghost btn-sm" type="button" @click="loadAttReviews">Muat Ulang</button>
          <div class="table-wrap" style="margin-top:10px">
            <table>
              <thead><tr><th>Tanggal</th><th>Nama</th><th>Masuk</th><th>Aksi</th></tr></thead>
              <tbody>
                <tr v-for="r in attReviewRows" :key="r.id">
                  <td>{{ r.date }}</td>
                  <td>{{ r.employee_name }}</td>
                  <td>{{ r.check_in_at ? formatDateTime(r.check_in_at) : '—' }}</td>
                  <td>
                    <button class="btn btn-primary btn-sm" type="button" @click="reviewAttendance(r, 'approve')">Setujui</button>
                    <button class="btn btn-danger btn-sm" type="button" @click="reviewAttendance(r, 'reject')">Tolak</button>
                  </td>
                </tr>
                <tr v-if="!attReviewRows.length"><td colspan="4">Tidak ada antrean.</td></tr>
              </tbody>
            </table>
          </div>
        </section>

        <section v-if="page==='emp-upah' && isPicWorkshop" class="card emp-card">
          <h2 class="brand">Laporan Upah Kerja</h2>
          <p class="filter-meta">Cabang Anda saja · baca saja (tanpa ubah job)</p>
          <div class="filter-bar">
            <div class="field">
              <label>Dari</label>
              <input type="date" v-model="reportForm.date_from" />
            </div>
            <div class="field">
              <label>Sampai</label>
              <input type="date" v-model="reportForm.date_to" />
            </div>
            <div class="field field-actions">
              <label>&nbsp;</label>
              <div style="display:flex;gap:8px;flex-wrap:wrap">
                <button class="btn btn-primary" type="button" :disabled="loading" @click="loadEmpUpahReport">Tampilkan</button>
                <button class="btn btn-ghost" type="button" :disabled="loading" @click="reportForm.type='upah'; exportReportPdf('attachment')">Export PDF</button>
                <button class="btn btn-ghost" type="button" :disabled="loading" @click="reportForm.type='upah'; exportReportPdf('inline')">Buka PDF</button>
              </div>
            </div>
          </div>

          <template v-if="reportResult && reportForm.type==='upah'">
            <div class="filter-meta" style="margin:12px 0">
              {{ reportResult.meta?.cabang }} · {{ reportResult.meta?.periode }}
            </div>
            <div class="report-kpi-row" style="margin-bottom:14px">
              <div class="report-kpi">
                <span class="report-kpi-label">Job</span>
                <strong>{{ reportResult.data?.jumlah || 0 }}</strong>
              </div>
              <div class="report-kpi">
                <span class="report-kpi-label">Gross</span>
                <strong class="value-income">{{ formatRp(reportResult.data?.total_gross) }}</strong>
              </div>
              <div class="report-kpi">
                <span class="report-kpi-label">Upah</span>
                <strong>{{ formatRp(reportResult.data?.total_net) }}</strong>
              </div>
              <div class="report-kpi">
                <span class="report-kpi-label">Toko</span>
                <strong>{{ formatRp(reportResult.data?.total_shop) }}</strong>
              </div>
            </div>

            <div class="panel-title" style="margin-bottom:8px">Ringkasan per Teknisi</div>
            <div class="table-wrap" style="margin-bottom:16px">
              <table>
                <thead>
                  <tr>
                    <th class="col-no">No</th>
                    <th>Teknisi</th>
                    <th>Job</th>
                    <th>Gross</th>
                    <th>% Upah</th>
                    <th>Upah</th>
                    <th>% Toko</th>
                    <th>Toko</th>
                    <th class="col-aksi">Aksi</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(row, idx) in (reportResult.data?.by_teknisi || [])" :key="'et'+row.employee_id">
                    <td class="col-no">{{ rowNo(idx) }}</td>
                    <td><strong>{{ row.teknisi }}</strong></td>
                    <td>{{ row.jumlah_job }}</td>
                    <td class="value-income">{{ formatRp(row.total_gross) }}</td>
                    <td>{{ formatPctShare(row.tech_share_pct) }}{{ row.pct_mixed ? '*' : '' }}</td>
                    <td><strong>{{ formatRp(row.total_net) }}</strong></td>
                    <td>{{ formatPctShare(row.shop_share_pct) }}{{ row.pct_mixed ? '*' : '' }}</td>
                    <td>{{ formatRp(row.total_shop) }}</td>
                    <td class="col-aksi">
                      <button class="btn btn-ghost btn-sm" type="button" @click="openReportUpahTechDetail(row)">Detail</button>
                    </td>
                  </tr>
                  <tr v-if="!(reportResult.data?.by_teknisi || []).length">
                    <td colspan="9">Tidak ada data upah di periode ini.</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </template>
        </section>

        <section v-if="page==='emp-brilink' && isPicEmployee" class="emp-pulsa-wrap">
          <div class="card emp-card">
            <h2 class="brand">Brilink</h2>
            <p class="filter-meta">Cabang Anda · rekap harian PIC</p>
            <div class="tx-form-top" style="margin-bottom:12px">
              <div class="field" style="max-width:220px">
                <label>Tanggal</label>
                <input type="date" v-model="brilinkDate" @change="loadBrilinkDaily" />
              </div>
              <div class="tx-daily-actions" style="flex-direction:row;gap:8px;align-items:flex-end">
                <button type="button" class="btn btn-ghost btn-sm" :disabled="loading" @click="copyBrilinkFromPrevious">Salin dari kemarin</button>
                <button type="button" class="btn btn-primary btn-sm" :disabled="loading" @click="shareBrilinkWhatsApp">Kirim WA</button>
              </div>
            </div>
            <div class="field" style="margin-bottom:10px">
              <label>Saldo kemarin (total)</label>
              <input :value="brilinkForm.previous_total" @input="onAmountInput($event, brilinkForm, 'previous_total')" inputmode="numeric" />
            </div>
            <div class="table-wrap">
              <table class="tx-draft-table">
                <thead><tr><th>Item</th><th>Nominal</th><th></th></tr></thead>
                <tbody>
                  <tr v-for="r in brilinkLines" :key="'ebl-'+r.key">
                    <td><input v-model="r.name" placeholder="KES, BRI, …" /></td>
                    <td><input :value="r.amount" @input="onAmountInput($event, r)" inputmode="numeric" placeholder="0" /></td>
                    <td><button type="button" class="btn btn-ghost btn-sm" @click="removeBrilinkLine(r.key)">Hapus</button></td>
                  </tr>
                </tbody>
                <tfoot>
                  <tr style="font-weight:700"><td>Total</td><td style="color:#0F766E">{{ formatRp(brilinkTotal) }}</td><td></td></tr>
                </tfoot>
              </table>
            </div>
            <button type="button" class="btn btn-ghost btn-sm" style="margin-top:8px" @click="addBrilinkLine">+ Baris</button>
            <div class="grid-4" style="margin-top:12px">
              <div class="card metric"><div class="label">Kemarin</div><div class="value" style="font-size:1rem">{{ formatRp(brilinkForm.previous_total) }}</div></div>
              <div class="card metric"><div class="label">Total</div><div class="value value-income" style="font-size:1rem">{{ formatRp(brilinkTotal) }}</div></div>
              <div class="card metric"><div class="label">Keuntungan</div><div class="value" style="font-size:1.1rem;color:#0F766E">{{ formatRp(brilinkProfit) }}</div></div>
            </div>
            <button type="button" class="btn btn-primary" style="margin-top:14px;width:100%" :disabled="loading" @click="submitBrilinkDaily">
              {{ brilinkForm.id ? 'Perbarui' : 'Simpan' }} Brilink
            </button>
          </div>
          <div class="card emp-card" style="margin-top:12px">
            <div class="panel-title">Riwayat</div>
            <div class="table-wrap">
              <table>
                <thead><tr><th>Tanggal</th><th>Total</th><th>Keuntungan</th><th></th></tr></thead>
                <tbody>
                  <tr v-for="r in brilinkHistory" :key="'ebh-'+r.id">
                    <td>{{ r.sheet_date }}</td>
                    <td>{{ formatRp(r.total_amount) }}</td>
                    <td style="font-weight:600;color:#0F766E">{{ formatRp(r.profit) }}</td>
                    <td>
                      <button class="btn btn-ghost btn-sm" type="button" @click="openBrilinkHistoryRow(r)">Buka</button>
                      <button class="btn btn-danger btn-sm" type="button" @click="deleteBrilinkSheet(r)">Hapus</button>
                    </td>
                  </tr>
                  <tr v-if="!brilinkHistory.length"><td colspan="4">Belum ada catatan.</td></tr>
                </tbody>
              </table>
            </div>
          </div>
        </section>

        <section v-if="page==='emp-pulsa' && isPicCounter" class="emp-pulsa-wrap">
          <div class="card emp-card">
            <h2 class="brand">Keuntungan Pulsa</h2>
            <p class="filter-meta">Cabang Anda · input harian PIC konter</p>
            <div class="tx-form-top" style="margin-bottom:12px">
              <div class="field" style="max-width:220px">
                <label>Tanggal</label>
                <input type="date" v-model="pulsaDate" @change="loadPulsaDaily" />
              </div>
              <div class="tx-daily-actions" style="flex-direction:row;gap:8px;align-items:flex-end">
                <button type="button" class="btn btn-ghost btn-sm" @click="pulsaShowProviders = !pulsaShowProviders">
                  {{ pulsaShowProviders ? 'Tutup Provider' : 'Kelola Provider' }}
                </button>
                <button
                  type="button"
                  class="btn btn-primary btn-sm"
                  :disabled="loading || !pulsaBalances.length"
                  @click="sharePulsaWhatsApp"
                >Kirim WA</button>
              </div>
            </div>
            <div v-if="pulsaShowProviders" class="card" style="margin-bottom:12px">
              <div class="tx-form-top">
                <div class="field" style="flex:1">
                  <label>Nama provider</label>
                  <input v-model="pulsaProviderForm.name" placeholder="DIGIPOS" @keyup.enter="submitPulsaProvider" />
                </div>
                <div style="align-self:flex-end">
                  <button type="button" class="btn btn-primary btn-sm" :disabled="loading" @click="submitPulsaProvider">Tambah</button>
                </div>
              </div>
              <div class="table-wrap" style="margin-top:8px">
                <table>
                  <thead><tr><th>Nama</th><th>Status</th><th></th></tr></thead>
                  <tbody>
                    <tr v-for="p in pulsaProviders" :key="'ep'+p.id">
                      <td>{{ p.name }}</td>
                      <td>{{ p.status === 'active' ? 'Aktif' : 'Nonaktif' }}</td>
                      <td>
                        <button class="btn btn-ghost btn-sm" type="button" @click="togglePulsaProvider(p)">{{ p.status === 'active' ? 'Nonaktifkan' : 'Aktifkan' }}</button>
                        <button class="btn btn-danger btn-sm" type="button" @click="deletePulsaProvider(p)">Hapus</button>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>

            <div class="panel-title">Saldo Provider</div>
            <div class="table-wrap">
              <table class="tx-draft-table">
                <thead>
                  <tr><th>Provider</th><th>Kemarin</th><th>Tambah</th><th>Sekarang</th><th>Terpakai</th></tr>
                </thead>
                <tbody>
                  <tr v-for="r in pulsaBalances" :key="'eb'+r.pulsa_provider_id">
                    <td style="font-weight:600">{{ r.provider_name }}</td>
                    <td><input :value="r.opening_balance" @input="onAmountInput($event, r, 'opening_balance')" inputmode="numeric" /></td>
                    <td><input :value="r.topup_amount" @input="onAmountInput($event, r, 'topup_amount')" inputmode="numeric" placeholder="0" /></td>
                    <td><input :value="r.closing_balance" @input="onAmountInput($event, r, 'closing_balance')" inputmode="numeric" placeholder="Isi" /></td>
                    <td style="font-weight:600;white-space:nowrap">{{ formatRp(pulsaUsedOf(r)) }}</td>
                  </tr>
                  <tr v-if="!pulsaBalances.length"><td colspan="5">Belum ada provider aktif.</td></tr>
                </tbody>
              </table>
            </div>

            <div class="field" style="margin-top:12px">
              <label>Uang Pulsa (kas fisik)</label>
              <input :value="pulsaForm.cash_on_hand" @input="onAmountInput($event, pulsaForm, 'cash_on_hand')" inputmode="numeric" placeholder="0" />
            </div>
            <div class="panel-title" style="margin-top:12px">Pengeluaran</div>
            <div class="table-wrap">
              <table class="tx-draft-table">
                <thead><tr><th>Keterangan</th><th>Nominal</th><th></th></tr></thead>
                <tbody>
                  <tr v-for="r in pulsaExpenses" :key="'ee'+r.key">
                    <td><input v-model="r.name" placeholder="HASMIN, GAS, …" /></td>
                    <td><input :value="r.amount" @input="onAmountInput($event, r)" inputmode="numeric" placeholder="0" /></td>
                    <td><button type="button" class="btn btn-ghost btn-sm" @click="removePulsaExpenseRow(r.key)">Hapus</button></td>
                  </tr>
                </tbody>
              </table>
            </div>
            <button type="button" class="btn btn-ghost btn-sm" style="margin-top:8px" @click="addPulsaExpenseRow">+ Baris</button>

            <div class="grid-4" style="margin-top:12px">
              <div class="card metric"><div class="label">Terpotong</div><div class="value value-expense" style="font-size:1rem">{{ formatRp(pulsaTotalUsed) }}</div></div>
              <div class="card metric"><div class="label">Pengeluaran</div><div class="value" style="font-size:1rem">{{ formatRp(pulsaTotalExpense) }}</div></div>
              <div class="card metric"><div class="label">Total Uang</div><div class="value value-income" style="font-size:1rem">{{ formatRp(pulsaTotalCash) }}</div></div>
              <div class="card metric"><div class="label">Keuntungan</div><div class="value" style="font-size:1.1rem;color:#0F766E">{{ formatRp(pulsaProfit) }}</div></div>
            </div>
            <button type="button" class="btn btn-primary" style="margin-top:14px;width:100%" :disabled="loading || !pulsaBalances.length" @click="submitPulsaDaily">
              {{ pulsaForm.id ? 'Perbarui' : 'Simpan' }} Keuntungan Pulsa
            </button>
          </div>

          <div class="card emp-card" style="margin-top:12px">
            <div class="panel-title">Riwayat</div>
            <div class="table-wrap">
              <table>
                <thead><tr><th>Tanggal</th><th>Terpotong</th><th>Total Uang</th><th>Keuntungan</th><th></th></tr></thead>
                <tbody>
                  <tr v-for="r in pulsaHistory" :key="'eh'+r.id">
                    <td>{{ formatDate(r.sheet_date) || r.sheet_date }}</td>
                    <td>{{ formatRp(r.total_used_balance) }}</td>
                    <td>{{ formatRp(r.total_cash) }}</td>
                    <td style="font-weight:600;color:#0F766E">{{ formatRp(r.profit) }}</td>
                    <td>
                      <button class="btn btn-ghost btn-sm" type="button" title="Buka detail catatan tanggal ini" @click="openPulsaHistoryRow(r)">Detail</button>
                      <button class="btn btn-danger btn-sm" type="button" @click="deletePulsaSheet(r)">Hapus</button>
                    </td>
                  </tr>
                  <tr v-if="!pulsaHistory.length"><td colspan="5">Belum ada catatan.</td></tr>
                </tbody>
              </table>
            </div>
          </div>
        </section>

        <section v-if="page==='profile'" class="card emp-card">
          <h2 class="brand">Akun</h2>
          <p>{{ user?.name }} · {{ user?.email }}</p>
          <p class="filter-meta">{{ roleLabel }}</p>
          <button class="btn btn-ghost" type="button" @click="doLogout">Keluar</button>
        </section>
      </main>
    </div>

    <div v-else class="app-shell">
      <aside class="sidebar">
        <div class="logo brand">BMS</div>
        <div class="logo-sub">Belawa Management System</div>

        <!-- OWNER: Utama + modul per grup -->
        <template v-if="isOwner">
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
              <button class="nav-btn" :class="{active: page==='brilink'}" @click="go('brilink')">
                <svg class="nav-ico" viewBox="0 0 24 24"><path d="M12 1v22"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
                <span>Brilink</span>
              </button>
              <button class="nav-btn" :class="{active: page==='recon'}" @click="go('recon')">
                <svg class="nav-ico" viewBox="0 0 24 24"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg>
                <span>Rekonsiliasi</span>
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
              <button class="nav-btn" :class="{active: page==='services'}" @click="go('services')">
                <svg class="nav-ico" viewBox="0 0 24 24"><path d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.76 3.76z"/></svg>
                <span>Catatan Servis</span>
              </button>
              <button class="nav-btn" :class="{active: page==='closings'}" @click="go('closings')">
                <svg class="nav-ico" viewBox="0 0 24 24"><path d="M4 19V5"/><path d="M4 19h16"/><path d="M8 15v4"/><path d="M12 11v8"/><path d="M16 7v12"/></svg>
                <span>Closing Harian &amp; Target</span>
              </button>
              <button class="nav-btn" :class="{active: page==='pulsa-profit'}" @click="go('pulsa-profit')">
                <svg class="nav-ico" viewBox="0 0 24 24"><path d="M12 1v22"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
                <span>Keuntungan Pulsa</span>
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

          <div class="nav-group">
            <button type="button" class="nav-group-toggle" :class="{open: navGroups.karyawan}" @click="toggleNavGroup('karyawan')">
              <span>Karyawan</span>
              <span class="chev"></span>
            </button>
            <div v-show="navGroups.karyawan" class="nav-group-items">
              <button class="nav-btn" :class="{active: page==='attendance'}" @click="go('attendance')">
                <svg class="nav-ico" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/><path d="M8 14h.01"/><path d="M12 14h.01"/><path d="M16 14h.01"/><path d="M8 18h.01"/><path d="M12 18h.01"/></svg>
                <span>Absensi</span>
              </button>
              <button class="nav-btn" :class="{active: page==='employees'}" @click="go('employees')">
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
              <button class="nav-btn" :class="{active: page==='cashflow'}" @click="go('cashflow')">
                <svg class="nav-ico" viewBox="0 0 24 24"><path d="M4 19V5"/><path d="M4 19h16"/><path d="M8 17V9"/><path d="M12 17V7"/><path d="M16 17v-4"/></svg>
                <span>Alur Kas (Input)</span>
              </button>
              <button class="nav-btn" :class="{active: page==='reports'}" @click="go('reports')">
                <svg class="nav-ico" viewBox="0 0 24 24"><path d="M4 19V5"/><path d="M4 19h16"/><path d="M8 15v4"/><path d="M12 11v8"/><path d="M16 7v12"/></svg>
                <span>Buat Laporan</span>
              </button>
            </div>
          </div>
        </template>

        <!-- ADMIN: Operasional harian dulu, pendukung di bawah -->
        <template v-else>
          <div class="nav-group">
            <button type="button" class="nav-group-toggle" :class="{open: navGroups.operasional}" @click="toggleNavGroup('operasional')">
              <span>Operasional</span>
              <span class="chev"></span>
            </button>
            <div v-show="navGroups.operasional" class="nav-group-items">
              <button class="nav-btn" :class="{active: page==='dashboard'}" @click="go('dashboard')">
                <svg class="nav-ico" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/></svg>
                <span>Dasbor</span>
              </button>
              <button class="nav-btn" :class="{active: page==='today-ops'}" @click="go('today-ops')">
                <svg class="nav-ico" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                <span>Hari Ini</span>
              </button>
              <button class="nav-btn" :class="{active: page==='attendance'}" @click="go('attendance')">
                <svg class="nav-ico" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/></svg>
                <span>Absensi</span>
              </button>
              <button v-if="canAccessBrilink && !isWorkshopBranch" class="nav-btn" :class="{active: page==='brilink'}" @click="go('brilink')">
                <svg class="nav-ico" viewBox="0 0 24 24"><path d="M12 1v22"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
                <span>Brilink</span>
              </button>
              <button v-if="canAccessClosings" class="nav-btn" :class="{active: page==='services'}" @click="go('services')">
                <svg class="nav-ico" viewBox="0 0 24 24"><path d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.76 3.76z"/></svg>
                <span>Catatan Servis</span>
              </button>
              <button v-if="canAccessClosings" class="nav-btn" :class="{active: page==='closings'}" @click="go('closings')">
                <svg class="nav-ico" viewBox="0 0 24 24"><path d="M4 19V5"/><path d="M4 19h16"/><path d="M8 15v4"/><path d="M12 11v8"/><path d="M16 7v12"/></svg>
                <span>Closing Harian &amp; Target</span>
              </button>
              <button v-if="canAccessPulsa" class="nav-btn" :class="{active: page==='pulsa-profit'}" @click="go('pulsa-profit')">
                <svg class="nav-ico" viewBox="0 0 24 24"><path d="M12 1v22"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
                <span>Keuntungan Pulsa</span>
              </button>
              <button class="nav-btn" :class="{active: page==='transactions'}" @click="go('transactions')">
                <svg class="nav-ico" viewBox="0 0 24 24"><path d="M12 3v18"/><path d="M7 8h7a3 3 0 010 6H9a3 3 0 000 6h8"/></svg>
                <span>Transaksi</span>
              </button>
              <button v-if="canAccessWorkshopWages" class="nav-btn" :class="{active: page==='workshop-wages'}" @click="go('workshop-wages')">
                <svg class="nav-ico" viewBox="0 0 24 24"><path d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.76 3.76z"/></svg>
                <span>Upah Kerja</span>
              </button>
              <button v-if="canAccessBrilink && isWorkshopBranch" class="nav-btn" :class="{active: page==='brilink'}" @click="go('brilink')">
                <svg class="nav-ico" viewBox="0 0 24 24"><path d="M12 1v22"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
                <span>Brilink</span>
              </button>
            </div>
          </div>

          <div class="nav-group">
            <button type="button" class="nav-group-toggle" :class="{open: navGroups.pendukung}" @click="toggleNavGroup('pendukung')">
              <span>Pendukung</span>
              <span class="chev"></span>
            </button>
            <div v-show="navGroups.pendukung" class="nav-group-items">
              <button class="nav-btn" :class="{active: page==='recon'}" @click="go('recon')">
                <svg class="nav-ico" viewBox="0 0 24 24"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg>
                <span>Rekonsiliasi</span>
              </button>
              <button class="nav-btn" :class="{active: page==='internal-transfer'}" @click="go('internal-transfer')">
                <svg class="nav-ico" viewBox="0 0 24 24"><path d="M4 12h16"/><path d="M12 4v16"/><circle cx="12" cy="12" r="9"/></svg>
                <span>Transfer Antar Akun</span>
              </button>
              <button class="nav-btn" :class="{active: page==='transfers'}" @click="go('transfers')">
                <svg class="nav-ico" viewBox="0 0 24 24"><path d="M7 7h11l-3-3"/><path d="M17 17H6l3 3"/><path d="M18 7v4"/><path d="M6 13v4"/></svg>
                <span>Transfer Antar Cabang</span>
              </button>
              <button class="nav-btn" :class="{active: page==='branch-accounts'}" @click="go('branch-accounts')">
                <svg class="nav-ico" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M7 10h4"/><path d="M7 14h10"/><path d="M15 8v4"/></svg>
                <span>Akun Cabang</span>
              </button>
              <button class="nav-btn" :class="{active: page==='branch-categories'}" @click="go('branch-categories')">
                <svg class="nav-ico" viewBox="0 0 24 24"><path d="M4 6h16"/><path d="M4 12h10"/><path d="M4 18h14"/><path d="M18 10v8"/><path d="M15 14h6"/></svg>
                <span>Kategori</span>
              </button>
            </div>
          </div>

          <div v-if="canAccessReports" class="nav-group">
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
        </template>

        <div v-if="isOwner" class="nav-group">
          <button type="button" class="nav-group-toggle" :class="{open: navGroups.sistem}" @click="toggleNavGroup('sistem')">
            <span>Sistem</span>
            <span class="chev"></span>
          </button>
          <div v-show="navGroups.sistem" class="nav-group-items">
            <button class="nav-btn" :class="{active: page==='adjustments'}" @click="go('adjustments')">
              <svg class="nav-ico" viewBox="0 0 24 24"><path d="M12 3v18"/><path d="M5 12h14"/><path d="M7 7l10 10"/><path d="M17 7L7 17"/></svg>
              <span>Penyesuaian</span>
            </button>
            <button class="nav-btn" :class="{active: page==='locks'}" @click="go('locks')">
              <svg class="nav-ico" viewBox="0 0 24 24"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 018 0v3"/></svg>
              <span>Kunci Periode</span>
            </button>
            <button class="nav-btn" :class="{active: page==='db-backup'}" @click="go('db-backup')">
              <svg class="nav-ico" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg>
              <span>Backup Database</span>
            </button>
            <button class="nav-btn" :class="{active: page==='profit-shares'}" @click="go('profit-shares')">
              <svg class="nav-ico" viewBox="0 0 24 24"><path d="M12 3v18"/><path d="M5 12h14"/><circle cx="12" cy="12" r="9"/></svg>
              <span>Bagi Hasil</span>
            </button>
            <button class="nav-btn" :class="{active: page==='kelola'}" @click="go('kelola')">
              <svg class="nav-ico" viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.5"/><path d="M5 20a7 7 0 0114 0"/><path d="M19 4v4"/><path d="M17 6h4"/></svg>
              <span>Kelola</span>
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
            <button v-if="showPwaInstall" class="btn btn-ghost btn-sm" type="button" title="Pasang BMS ke layar utama" @click="installPwaApp">Pasang App</button>
            <button class="btn btn-ghost btn-sm" type="button" title="Muat ulang aplikasi dari server" @click="hardReloadApp">Muat Ulang</button>
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
            <div v-if="ownerData?.pulsa" class="card metric">
              <div class="label">Keuntungan Pulsa</div>
              <div class="value value-income">{{ formatRp(ownerData?.pulsa?.total_profit) }}</div>
              <div class="metric-sub">{{ ownerData?.pulsa?.sheet_count || 0 }} hari terisi · <button type="button" class="link-btn" @click="openReportFromDash('keuntungan-pulsa')">Laporan</button></div>
            </div>
            <div class="card metric">
              <div class="label">Keuntungan Brilink</div>
              <div class="value value-income">{{ formatRp(ownerData?.brilink?.total_profit) }}</div>
              <div class="metric-sub">{{ ownerData?.brilink?.sheet_count || 0 }} hari terisi · <button type="button" class="link-btn" @click="openReportFromDash('brilink')">Laporan</button></div>
            </div>
          </div>

          <div class="grid-4" style="margin-top:14px">
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

          <div class="card" style="margin-top:14px">
            <div class="panel-title" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
              <span>Hasil Akhir per Cabang</span>
              <span class="muted" style="font-size:.85rem;font-weight:500">{{ ownerDashPeriodLabel }} · klik laporan untuk detail</span>
            </div>
            <div class="table-wrap">
              <table class="scorecard-table">
                <thead>
                  <tr>
                    <th>Cabang</th>
                    <th>Omzet</th>
                    <th>Beban</th>
                    <th>Profit</th>
                    <th>Saldo</th>
                    <th>Modul</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="r in (ownerData?.hasil_per_cabang || [])" :key="'hp'+r.branch_id">
                    <td><strong>{{ r.nama_cabang }}</strong><div class="muted" style="font-size:.78rem">{{ r.is_workshop ? 'Bengkel' : 'Konter' }}</div></td>
                    <td class="value-income">{{ formatRp(r.omzet) }}</td>
                    <td class="value-expense">{{ formatRp(r.beban) }}</td>
                    <td :class="Number(r.profit) >= 0 ? 'value-income' : 'value-expense'"><strong>{{ formatRp(r.profit) }}</strong></td>
                    <td>{{ formatRp(r.saldo) }}</td>
                    <td style="font-size:.82rem;color:#64748B">
                      <template v-if="!r.is_workshop">
                        Servis {{ formatRp(r.service_profit) }} · Closing {{ r.closing_qty ?? 0 }}/{{ r.closing_target ?? 0 }}
                        · Pulsa {{ formatRp(r.pulsa_profit) }} · Brilink {{ formatRp(r.brilink_profit) }}
                      </template>
                      <template v-else>
                        Upah {{ formatRp(r.workshop_tech_net) }} · Brilink {{ formatRp(r.brilink_profit) }}
                      </template>
                    </td>
                    <td>
                      <button type="button" class="btn btn-sm btn-ghost" @click="openReportFromDash('ringkasan', r.branch_id)">Laporan</button>
                    </td>
                  </tr>
                  <tr v-if="!(ownerData?.hasil_per_cabang || []).length">
                    <td colspan="7">Belum ada data cabang pada periode ini.</td>
                  </tr>
                </tbody>
              </table>
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

          <div style="margin-top:14px;display:flex;justify-content:flex-end">
            <button type="button" class="btn btn-ghost btn-sm" @click="toggleOwnerDashExtra">
              {{ ownerDashExtraOpen ? 'Sembunyikan chart & top kategori' : 'Tampilkan chart & top kategori' }}
            </button>
          </div>

          <div v-show="ownerDashExtraOpen" class="grid-2" style="margin-top:14px">
            <div class="card">
              <div class="panel-title">{{ ownerDashBranchId ? 'Ringkasan Cabang' : 'Komparasi Cabang' }}</div>
              <div class="chart-box"><canvas id="chartBar"></canvas></div>
            </div>
            <div class="card">
              <div class="panel-title">{{ ownerDashBranchId ? 'Arus Kas Harian' : 'Tren Arus Kas' }}</div>
              <div class="chart-box"><canvas id="chartLine"></canvas></div>
            </div>
          </div>

          <div v-show="ownerDashExtraOpen" class="card" style="margin-top:14px">
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
            <button class="btn btn-primary" type="button" @click="go('today-ops')">Alur Hari Ini</button>
          </div>

          <div v-if="periodLocked" class="banner-lock">
            Periode Pembukuan Bulan Ini Telah Dikunci Oleh Owner. Anda Tidak Dapat Menambah atau Mengubah Data.
          </div>

          <div class="card" style="margin-bottom:14px">
            <div class="panel-title">Checklist Hari Ini</div>
            <div class="today-ops-grid">
              <button
                v-for="item in (branchData?.daily_ops || [])"
                :key="'dash-'+item.key"
                type="button"
                class="today-ops-card"
                :class="{ done: item.done }"
                @click="go(item.page)"
              >
                <span class="today-ops-status">{{ item.done ? 'Selesai' : 'Belum' }}</span>
                <strong>{{ item.label }}</strong>
                <span class="muted">{{ item.detail }}</span>
              </button>
            </div>
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
        <section v-if="page==='today-ops' && canAccessTodayOps">
          <div class="page-head">
            <div>
              <h2 class="brand">Hari Ini</h2>
              <p>Alur kerja harian {{ user?.branch?.name }} — kerjakan berurutan, lalu cek status</p>
            </div>
            <button class="btn btn-ghost" type="button" :disabled="loading" @click="loadBranchDashboard()">Muat Ulang</button>
          </div>
          <div class="today-ops-grid">
            <button
              v-for="item in (branchData?.daily_ops || [])"
              :key="'ops-'+item.key"
              type="button"
              class="today-ops-card"
              :class="{ done: item.done }"
              @click="go(item.page)"
            >
              <span class="today-ops-status">{{ item.done ? 'Selesai' : 'Belum' }}</span>
              <strong>{{ item.label }}</strong>
              <span class="muted">{{ item.detail }}</span>
              <span class="today-ops-cta">Buka →</span>
            </button>
          </div>
          <p class="closing-hint" style="margin-top:16px">
            Checklist ini hanya status pengisian hari ini. Modul Brilink / Pulsa / Closing / Servis tetap berdiri sendiri (tidak otomatis masuk kas).
          </p>
        </section>

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

          <div id="tx-form-card" class="card card-tx-form" :class="{ 'tx-form-editing': txDayEdit.active }">
            <div class="panel-title">
              {{ txDayEdit.active ? 'Edit Transaksi — Harian' : 'Form Transaksi — Multi Input' }}
            </div>
            <p class="closing-hint" style="margin-top:0">
              <template v-if="txDayEdit.active">
                Semua transaksi tanggal terpilih dimuat di bawah. Ubah, tambah, atau hapus baris lalu simpan sekaligus.
              </template>
              <template v-else>
                Satu tanggal untuk semua baris. Isi beberapa pemasukan/pengeluaran sekaligus, baris kosong diabaikan.
              </template>
            </p>
            <div class="tx-form">
              <div class="tx-form-top">
                <div class="field">
                  <label>Tanggal</label>
                  <input type="date" v-model="txForm.transaction_date" :disabled="periodLocked || txDayEdit.active" />
                </div>
                <div v-if="isOwner" class="field">
                  <label>Cabang</label>
                  <select v-model="txForm.branch_id" :disabled="periodLocked || txDayEdit.active">
                    <option disabled value="">Pilih cabang</option>
                    <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                  </select>
                </div>
                <div class="tx-daily-actions">
                  <button
                    type="button"
                    class="btn btn-ghost"
                    :disabled="loading"
                    @click="openTxDailyReport"
                  >Laporan Harian</button>
                </div>
              </div>

              <div class="table-wrap tx-draft-wrap">
                <table class="tx-draft-table">
                  <thead>
                    <tr>
                      <th style="width:36px">#</th>
                      <th>Tipe</th>
                      <th>Kategori</th>
                      <th>Akun</th>
                      <th>Nominal</th>
                      <th>Deskripsi</th>
                      <th style="width:64px"></th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="(r, idx) in txDraftRows" :key="r.key">
                      <td class="muted">{{ idx + 1 }}</td>
                      <td>
                        <select v-model="r.type" :disabled="periodLocked" @change="onTxDraftTypeChange(r)">
                          <option value="income">Pemasukan</option>
                          <option value="expense">Pengeluaran</option>
                        </select>
                      </td>
                      <td>
                        <select v-model="r.category_id" :disabled="periodLocked">
                          <option disabled value="">Pilih</option>
                          <option v-for="c in categoriesForTxType(r.type)" :key="r.key+'-c-'+c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                      </td>
                      <td>
                        <select v-model="r.account_id" :disabled="periodLocked">
                          <option disabled value="">Pilih</option>
                          <option v-for="a in accounts" :key="r.key+'-a-'+a.id" :value="a.id">{{ a.name }}</option>
                        </select>
                      </td>
                      <td>
                        <input
                          :value="r.amount"
                          @input="onAmountInput($event, r)"
                          inputmode="numeric"
                          placeholder="0"
                          :disabled="periodLocked"
                        />
                      </td>
                      <td>
                        <input v-model="r.description" placeholder="Opsional" :disabled="periodLocked" />
                      </td>
                      <td>
                        <button type="button" class="btn btn-ghost btn-sm" :disabled="periodLocked" @click="removeTxDraftRow(r.key)">Hapus</button>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>

              <div
                class="tx-hp-match"
                :class="{
                  ok: txFisikCocok,
                  lebih: txFisikHasInput && !txFisikCocok && txFisikSelisih > 0,
                  kurang: txFisikHasInput && !txFisikCocok && txFisikSelisih < 0,
                }"
              >
                <div class="tx-cash-head">
                  <div>
                    <div class="tx-cash-block-title">Cek uang fisik hari ini</div>
                    <p class="tx-fisik-note">HP + Pulsa − Fisik − Pengeluaran</p>
                  </div>
                  <div class="tx-hp-hasil-pill">
                    <span v-if="!txFisikHasInput" class="muted">Isi fisik</span>
                    <span v-else-if="txFisikCocok" class="value-income">Cocok</span>
                    <span v-else-if="txFisikSelisih > 0" class="value-income">Lebih {{ formatRp(txFisikStatusAmount) }}</span>
                    <span v-else class="value-expense">Kurang {{ formatRp(txFisikStatusAmount) }}</span>
                  </div>
                </div>
                <div class="tx-hp-match-grid">
                  <label class="tx-fisik-field tx-hp-fisik">
                    <span class="tx-hp-label">Penjualan HP</span>
                    <input
                      class="tx-hp-sales-input"
                      :value="txDraftHpSalesDisplay"
                      @input="onTxHpSalesInput"
                      inputmode="numeric"
                      placeholder="0"
                      :disabled="periodLocked"
                    />
                  </label>
                  <div class="tx-hp-metric">
                    <span class="tx-hp-label">Pulsa</span>
                    <strong class="value-income tx-hp-value">{{ formatRp(txDraftPulsaSalesCash) }}</strong>
                  </div>
                  <label class="tx-fisik-field tx-hp-fisik">
                    <span class="tx-hp-label">Uang fisik</span>
                    <input
                      :value="txFisik.physical"
                      @input="onTxFisikInput"
                      inputmode="numeric"
                      placeholder="0"
                      :disabled="periodLocked"
                    />
                  </label>
                  <div class="tx-hp-metric">
                    <span class="tx-hp-label">Total pengeluaran</span>
                    <strong class="value-expense tx-hp-value">{{ formatRp(txDraftExpense) }}</strong>
                  </div>
                </div>
              </div>

              <div class="tx-draft-actions">
                <button type="button" class="btn btn-ghost" style="width:auto" :disabled="periodLocked || loading" @click="addTxDraftRow('income')">+ Pemasukan</button>
                <button type="button" class="btn btn-ghost" style="width:auto" :disabled="periodLocked || loading" @click="addTxDraftRow('expense')">+ Pengeluaran</button>
                <button
                  v-if="!txDayEdit.active"
                  type="button"
                  class="btn btn-ghost"
                  style="width:auto"
                  :disabled="periodLocked || loading"
                  @click="resetTxDraftRows"
                >Kosongkan</button>
                <div class="tx-draft-summary">
                  {{ txDayEdit.active ? 'Siap ubah' : 'Siap simpan' }} <strong>{{ txDraftFilled.length }}</strong> baris
                </div>
                <div class="tx-draft-save-group">
                  <button
                    v-if="txDayEdit.active"
                    type="button"
                    class="btn btn-ghost"
                    style="width:auto"
                    :disabled="loading"
                    @click="cancelTxDayEdit"
                  >Batal Edit</button>
                  <button
                    type="button"
                    class="btn btn-primary"
                    style="width:auto"
                    :disabled="periodLocked || loading || (!txDayEdit.active && !txDraftFilled.length)"
                    @click="submitTxDraftBatch"
                  >
                    {{ txDayEdit.active
                      ? ('Simpan Perubahan' + (txDraftFilled.length ? (' (' + txDraftFilled.length + ')') : ''))
                      : ('Simpan ' + (txDraftFilled.length || '') + ' Transaksi') }}
                  </button>
                </div>
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
            <div class="filter-meta">{{ txMeta.total }} transaksi · {{ transactionGroups.length }} hari · tekan Edit untuk ubah semua baris tanggal itu</div>
            <div v-if="!transactionGroups.length" class="empty-hint" style="padding:16px 0">Tidak ada transaksi sesuai filter.</div>
            <div v-for="g in transactionGroups" :key="g.key" class="tx-day-group tx-day-summary-only">
              <div class="tx-day-head">
                <div class="tx-day-head-main">
                  <strong>{{ formatTxDailyDateLabel(g.date) }}</strong>
                  <span v-if="isOwner" class="muted"> · {{ g.branch_name }}</span>
                  <span class="muted"> · {{ g.count }} transaksi</span>
                  <span class="muted">
                    · <span class="value-income">{{ formatRp(g.income) }}</span>
                    / <span class="value-expense">{{ formatRp(g.expense) }}</span>
                    · <strong :class="amountClass(g.net)">{{ formatRp(g.net) }}</strong>
                  </span>
                </div>
                <div class="tx-day-head-actions">
                  <button
                    type="button"
                    class="btn btn-ghost btn-sm"
                    :disabled="loading"
                    @click="openTxGroupDetail(g)"
                  >Detail</button>
                  <template v-if="periodLocked">
                    <span class="muted" style="font-size:.8rem">Terkunci</span>
                  </template>
                  <button
                    v-else
                    type="button"
                    class="btn btn-primary btn-sm"
                    :disabled="loading"
                    @click="openEditTxDay(g)"
                  >Edit</button>
                </div>
              </div>
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

        <!-- BRILINK (semua cabang) -->
        <section v-if="page==='brilink' && canAccessBrilink && !isEmployeeRole" class="sheet-daily-page">
          <div class="page-head">
            <div>
              <h2 class="brand">Brilink</h2>
              <p v-if="canInputBrilink">Rekap saldo harian — keuntungan = total hari ini − saldo kemarin (belum masuk kas otomatis)</p>
              <p v-else>Pantau saja — input/ubah/hapus hanya Admin atau PIC cabang</p>
            </div>
          </div>

          <div class="card card-tx-form">
            <div class="tx-form-top" style="margin-bottom:12px">
              <div class="field">
                <label>Tanggal</label>
                <input type="date" v-model="brilinkDate" @change="loadBrilinkDaily" />
              </div>
              <div v-if="isOwner" class="field">
                <label>Cabang</label>
                <select v-model="brilinkBranchId" @change="loadBrilinkPage">
                  <option disabled value="">Pilih cabang</option>
                  <option v-for="b in branches" :key="'bl-'+b.id" :value="b.id">{{ b.name }}</option>
                </select>
              </div>
              <div class="tx-daily-actions" style="flex-direction:row;gap:8px;align-items:flex-end">
                <button
                  v-if="canInputBrilink"
                  type="button"
                  class="btn btn-ghost"
                  :disabled="loading"
                  @click="copyBrilinkFromPrevious"
                >Salin dari kemarin</button>
                <button
                  type="button"
                  class="btn btn-primary"
                  :disabled="loading"
                  @click="shareBrilinkWhatsApp"
                >Kirim WA</button>
              </div>
            </div>

            <div class="field" style="max-width:280px;margin-bottom:12px">
              <label>Saldo kemarin (total)</label>
              <input
                :value="brilinkForm.previous_total"
                @input="onAmountInput($event, brilinkForm, 'previous_total')"
                inputmode="numeric"
                :disabled="!canInputBrilink"
              />
              <small class="muted">Otomatis dari total hari sebelumnya — untuk hitung keuntungan</small>
            </div>

            <div class="panel-title">Item Baris</div>
            <p class="closing-hint" style="margin-top:0">Tidak ada default. Tambah item, atau salin nama dari kemarin (nominal kosong).</p>
            <div class="table-wrap tx-draft-wrap">
              <table class="tx-draft-table">
                <thead>
                  <tr>
                    <th style="width:36px">#</th>
                    <th>Item</th>
                    <th>Nominal</th>
                    <th style="width:64px"></th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(r, idx) in brilinkLines" :key="r.key">
                    <td class="muted">{{ idx + 1 }}</td>
                    <td><input v-model="r.name" placeholder="KES, BRI, …" :disabled="!canInputBrilink" /></td>
                    <td>
                      <input :value="r.amount" @input="onAmountInput($event, r)" inputmode="numeric" placeholder="0" :disabled="!canInputBrilink" />
                    </td>
                    <td>
                      <button v-if="canInputBrilink" type="button" class="btn btn-ghost btn-sm" @click="removeBrilinkLine(r.key)">Hapus</button>
                    </td>
                  </tr>
                </tbody>
                <tfoot>
                  <tr style="font-weight:700;background:#f1f5f9">
                    <td></td>
                    <td>Total</td>
                    <td style="color:#0F766E">{{ formatRp(brilinkTotal) }}</td>
                    <td></td>
                  </tr>
                </tfoot>
              </table>
            </div>

            <div v-if="canInputBrilink" style="margin-top:8px">
              <button type="button" class="btn btn-ghost btn-sm" @click="addBrilinkLine">+ Baris</button>
            </div>

            <div class="grid-4" style="margin-top:14px">
              <div class="card metric">
                <div class="label">Saldo Kemarin</div>
                <div class="value" style="font-size:1rem">{{ formatRp(brilinkForm.previous_total) }}</div>
              </div>
              <div class="card metric">
                <div class="label">Total Hari Ini</div>
                <div class="value value-income" style="font-size:1rem">{{ formatRp(brilinkTotal) }}</div>
              </div>
              <div class="card metric">
                <div class="label">Keuntungan</div>
                <div class="value" style="font-size:1.15rem;color:#0F766E">{{ formatRp(brilinkProfit) }}</div>
              </div>
              <div class="field" style="margin:0">
                <label>Catatan</label>
                <input v-model="brilinkForm.note" placeholder="Opsional" :disabled="!canInputBrilink" />
              </div>
            </div>

            <div v-if="canInputBrilink" class="tx-draft-actions" style="margin-top:16px">
              <div class="tx-draft-summary">
                Keuntungan <strong>{{ formatRp(brilinkProfit) }}</strong>
              </div>
              <button
                type="button"
                class="btn btn-primary"
                style="width:auto;margin-left:auto"
                :disabled="loading"
                @click="submitBrilinkDaily"
              >
                {{ brilinkForm.id ? 'Perbarui' : 'Simpan' }} Brilink
              </button>
            </div>
          </div>

          <div class="card" style="margin-top:14px">
            <div class="panel-title">Riwayat Catatan</div>
            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Tanggal</th>
                    <th v-if="isOwner">Cabang</th>
                    <th>Saldo Kemarin</th>
                    <th>Total</th>
                    <th>Keuntungan</th>
                    <th v-if="canInputBrilink">Aksi</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="r in brilinkHistory" :key="r.id" style="cursor:pointer" @click="openBrilinkHistoryRow(r)">
                    <td>{{ r.sheet_date }}</td>
                    <td v-if="isOwner">{{ r.branch?.name }}</td>
                    <td>{{ formatRp(r.previous_total) }}</td>
                    <td class="value-income">{{ formatRp(r.total_amount) }}</td>
                    <td style="font-weight:600;color:#0F766E">{{ formatRp(r.profit) }}</td>
                    <td v-if="canInputBrilink" @click.stop>
                      <button class="btn btn-ghost btn-sm" type="button" @click="openBrilinkHistoryRow(r)">Buka</button>
                      <button class="btn btn-danger btn-sm" type="button" @click="deleteBrilinkSheet(r)">Hapus</button>
                    </td>
                  </tr>
                  <tr v-if="!brilinkHistory.length">
                    <td :colspan="isOwner ? (canInputBrilink ? 6 : 5) : (canInputBrilink ? 5 : 4)">Belum ada catatan.</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </section>

        <!-- RECON -->
        <section v-if="page==='recon'">
          <div class="page-head">
            <div>
              <h2 class="brand">Rekonsiliasi</h2>
              <p>Cocokkan saldo fisik vs sistem per akun — tidak mengubah pembukuan</p>
              <p v-if="isOwner" class="muted" style="margin-top:6px">
                Ada selisih yang perlu dikoreksi?
                <button type="button" class="link-btn" @click="go('adjustments')">Sistem → Penyesuaian</button>
              </p>
            </div>
          </div>

          <div class="card adj-form-card">
            <div class="panel-head" style="margin-bottom:10px">
              <div class="panel-title" style="margin:0">Form Rekonsiliasi</div>
            </div>
            <div class="adj-form recon-form">
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
                <label>Saldo Sistem</label>
                <input :value="formatRp(reconSystemBalance)" disabled />
              </div>
              <div class="field">
                <label>Saldo Fisik</label>
                <input :value="reconForm.physical_balance" @input="onPhysicalInput" inputmode="numeric" placeholder="Masukkan saldo fisik akun ini" />
              </div>
              <div class="field">
                <label>Selisih</label>
                <div class="diff-preview recon-diff-box" :class="Math.abs(reconDifference) < 0.01 ? 'diff-zero' : 'diff-nonzero'">
                  {{ formatRp(reconDifference) }}
                </div>
              </div>
              <div class="adj-form-actions">
                <button
                  class="btn btn-primary"
                  type="button"
                  :disabled="loading || !reconForm.account_id"
                  @click="submitReconciliation"
                >Simpan Rekonsiliasi</button>
              </div>
            </div>
          </div>

          <div v-if="(branchData?.rekonsiliasi_hari_ini || []).length" class="card">
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
          <div class="card card-tx-form">
            <div class="panel-title">Form Transfer</div>
            <p class="closing-hint" style="margin-top:0">
              Pilih tanggal, akun asal, akun tujuan, lalu nominal. Akun asal dan tujuan harus berbeda.
            </p>
            <div class="tx-form">
              <div class="tx-form-top">
                <div class="field">
                  <label>Tanggal</label>
                  <input type="date" v-model="internalTransferForm.transaction_date" :disabled="periodLocked" />
                </div>
                <div v-if="isOwner" class="field">
                  <label>Cabang</label>
                  <select v-model="internalTransferForm.branch_id" :disabled="periodLocked">
                    <option disabled value="">Pilih cabang</option>
                    <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                  </select>
                </div>
              </div>

              <div class="tx-form-main tx-transfer-main">
                <div class="field">
                  <label>Akun asal</label>
                  <select v-model="internalTransferForm.from_account_id" :disabled="periodLocked">
                    <option disabled value="">Pilih akun</option>
                    <option v-for="a in accounts" :key="'from-'+a.id" :value="a.id">{{ a.name }}</option>
                  </select>
                </div>
                <div class="field">
                  <label>Akun tujuan</label>
                  <select v-model="internalTransferForm.to_account_id" :disabled="periodLocked">
                    <option disabled value="">Pilih akun</option>
                    <option
                      v-for="a in accounts"
                      :key="'to-'+a.id"
                      :value="a.id"
                      :disabled="Number(a.id) === Number(internalTransferForm.from_account_id)"
                    >{{ a.name }}</option>
                  </select>
                </div>
                <div class="field">
                  <label>Nominal</label>
                  <input
                    :value="internalTransferForm.amount"
                    @input="onAmountInput($event, internalTransferForm)"
                    inputmode="numeric"
                    placeholder="Ketik nominal"
                    :disabled="periodLocked"
                  />
                </div>
              </div>

              <div v-if="internalTransferPreview" class="tx-transfer-preview">
                <strong>{{ internalTransferPreview.from }}</strong>
                <span class="muted">→</span>
                <strong>{{ internalTransferPreview.to }}</strong>
                <span class="muted">·</span>
                <strong>{{ formatRp(internalTransferPreview.amount) }}</strong>
                <span class="muted">· {{ internalTransferPreview.date }}</span>
              </div>

              <div class="tx-form-bottom">
                <div class="field tx-desc-field">
                  <label>Deskripsi <span class="opt">(opsional)</span></label>
                  <textarea
                    rows="2"
                    v-model="internalTransferForm.description"
                    placeholder="Catatan singkat…"
                    :disabled="periodLocked"
                  ></textarea>
                </div>
                <button
                  class="btn btn-primary btn-tx-save"
                  :disabled="periodLocked || loading || !internalTransferPreview"
                  @click="submitInternalTransfer"
                >
                  Simpan Transfer
                </button>
              </div>
            </div>
          </div>
        </section>

        <!-- ADJUSTMENTS -->
        <section v-if="page==='adjustments' && isOwner">
          <div class="page-head">
            <div>
              <h2 class="brand">Penyesuaian Saldo</h2>
              <p>Koreksi saldo sistem (mengubah pembukuan) — beda dari rekonsiliasi yang hanya mengecek selisih</p>
            </div>
          </div>

          <div id="adjustment-form-card" class="card adj-form-card">
            <div class="panel-head" style="margin-bottom:10px">
              <div class="panel-title" style="margin:0">Form Penyesuaian</div>
              <button type="button" class="btn btn-ghost btn-sm" :disabled="loading" @click="clearAdjustmentForm(true)">Kosongkan</button>
            </div>
            <p v-if="adjustmentForm.reconciliation_id" class="filter-meta" style="margin-top:0">
              Terhubung ke rekonsiliasi #{{ adjustmentForm.reconciliation_id }} — setelah disimpan, baris itu akan
              <strong>terkunci</strong> agar tidak diproses dua kali.
            </p>
            <div class="adj-form">
              <div class="field">
                <label>Cabang</label>
                <select v-model="adjustmentForm.branch_id" :disabled="!!adjustmentForm.reconciliation_id">
                  <option disabled value="">Pilih cabang</option>
                  <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                </select>
              </div>
              <div class="field">
                <label>Akun</label>
                <select v-model="adjustmentForm.account_id" :disabled="!!adjustmentForm.reconciliation_id">
                  <option disabled value="">Pilih akun</option>
                  <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.name }}</option>
                </select>
              </div>
              <div class="field">
                <label>Tanggal</label>
                <input type="date" v-model="adjustmentForm.transaction_date" />
              </div>
              <div class="field adj-form-type">
                <label>Tipe</label>
                <div class="radio-row">
                  <div
                    class="radio-pill"
                    :class="{'active-income': adjustmentForm.type==='income', 'is-disabled': !!adjustmentForm.reconciliation_id}"
                    @click="!adjustmentForm.reconciliation_id && (adjustmentForm.type='income')"
                  >Pemasukan</div>
                  <div
                    class="radio-pill"
                    :class="{'active-expense': adjustmentForm.type==='expense', 'is-disabled': !!adjustmentForm.reconciliation_id}"
                    @click="!adjustmentForm.reconciliation_id && (adjustmentForm.type='expense')"
                  >Pengeluaran</div>
                </div>
              </div>
              <div class="field">
                <label>Nominal</label>
                <input
                  :value="adjustmentForm.amount"
                  @input="onAmountInput($event, adjustmentForm)"
                  inputmode="numeric"
                  placeholder="Ketik nominal"
                  :disabled="!!adjustmentForm.reconciliation_id"
                />
              </div>
              <div class="field adj-form-reason">
                <label>Alasan (wajib)</label>
                <textarea rows="2" v-model="adjustmentForm.reason" placeholder="Jelaskan alasan penyesuaian"></textarea>
              </div>
              <div class="adj-form-actions">
                <button class="btn btn-primary" type="button" :disabled="loading" @click="submitAdjustment">Simpan Penyesuaian</button>
              </div>
            </div>
          </div>

          <div class="card">
            <div class="panel-head" style="margin-bottom:8px">
              <div class="panel-title" style="margin:0">Selisih dari Rekonsiliasi</div>
              <button
                type="button"
                class="btn btn-ghost btn-sm"
                :disabled="adjustmentReconLoading || loading"
                @click="loadAdjustmentReconAlerts"
              >Muat Ulang</button>
            </div>
            <p class="filter-meta" style="margin-top:0">
              60 hari terakhir yang ada selisih. Yang sudah disesuaikan berstatus
              <strong>Terkunci</strong> dan tidak bisa diisi lagi, kecuali Admin cek ulang rekonsiliasi.
            </p>
            <div v-if="adjustmentReconLoading" class="empty-hint">Memuat…</div>
            <div v-else class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Tanggal Cek</th>
                    <th>Cabang</th>
                    <th>Akun</th>
                    <th>Sistem</th>
                    <th>Fisik</th>
                    <th>Selisih</th>
                    <th>Status</th>
                    <th class="col-aksi">Aksi</th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="r in adjustmentReconAlerts"
                    :key="r.id"
                    :class="{
                      'adj-recon-row-locked': r.is_adjusted,
                      'adj-recon-row-active': Number(adjustmentForm.reconciliation_id) === Number(r.id),
                    }"
                  >
                    <td>{{ formatDate(r.reconciliation_date) || r.reconciliation_date }}</td>
                    <td>{{ r.branch?.name || '—' }}</td>
                    <td>{{ r.account?.name || '—' }}</td>
                    <td>{{ formatRp(r.system_balance) }}</td>
                    <td>{{ formatRp(r.physical_balance) }}</td>
                    <td>
                      <strong :class="Number(r.difference) < 0 ? 'value-expense' : 'value-income'">
                        {{ formatRp(r.difference) }}
                      </strong>
                    </td>
                    <td>
                      <span v-if="r.is_adjusted" class="badge badge-approved" :title="r.adjusted_by?.name ? ('Oleh ' + r.adjusted_by.name) : ''">Terkunci</span>
                      <span v-else class="badge badge-pending">Belum</span>
                    </td>
                    <td class="col-aksi">
                      <button
                        v-if="!r.is_adjusted"
                        type="button"
                        class="btn btn-primary btn-sm"
                        :disabled="loading || Number(adjustmentForm.reconciliation_id) === Number(r.id)"
                        @click="fillAdjustmentFromRecon(r)"
                      >{{ Number(adjustmentForm.reconciliation_id) === Number(r.id) ? 'Sedang Diisi' : 'Isi dari Selisih' }}</button>
                      <span v-else class="muted" style="font-size:.8rem">Sudah disesuaikan</span>
                    </td>
                  </tr>
                  <tr v-if="!adjustmentReconAlerts.length">
                    <td colspan="8">Tidak ada rekonsiliasi dengan selisih.</td>
                  </tr>
                </tbody>
              </table>
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
            <template v-if="serviceForm.id">
              <div class="panel-title">Ubah Catatan Servis</div>
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
                      <option v-for="e in serviceTechnicians" :key="'edit-'+e.id" :value="e.id">{{ e.name }}</option>
                    </select>
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
                    <button class="btn btn-ghost" type="button" @click="resetServiceForm">Batal</button>
                    <button class="btn btn-primary btn-tx-save" :disabled="loading" @click="submitService">Perbarui</button>
                  </div>
                </div>
              </div>
            </template>

            <template v-else>
              <div class="panel-title">Form Servis — Multi Input</div>
              <p class="closing-hint" style="margin-top:0">
                Satu tanggal untuk semua baris. Isi beberapa catatan sekaligus; baris kosong diabaikan. Total = harga − modal.
              </p>
              <div class="tx-form">
                <div class="tx-form-top">
                  <div class="field">
                    <label>Tanggal</label>
                    <input type="date" v-model="serviceForm.service_date" />
                  </div>
                  <small v-if="!serviceTechnicians.length" class="muted" style="align-self:end;padding-bottom:8px">
                    Belum ada teknisi aktif di cabang. Minta Owner set jabatan Teknisi di Data Karyawan.
                  </small>
                </div>

                <div class="table-wrap tx-draft-wrap">
                  <table class="tx-draft-table svc-draft-table">
                    <thead>
                      <tr>
                        <th style="width:36px">#</th>
                        <th>Teknisi</th>
                        <th>Merek</th>
                        <th>Type</th>
                        <th>Kerusakan</th>
                        <th>Modal</th>
                        <th>Harga</th>
                        <th>Total</th>
                        <th>Catatan</th>
                        <th style="width:64px"></th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-for="(r, idx) in svcDraftRows" :key="r.key">
                        <td class="muted">{{ idx + 1 }}</td>
                        <td>
                          <select v-model="r.employee_id">
                            <option value="">Pilih</option>
                            <option v-for="e in serviceTechnicians" :key="r.key+'-e-'+e.id" :value="e.id">{{ e.name }}</option>
                          </select>
                        </td>
                        <td>
                          <input v-model="r.brand" placeholder="OPPO…" />
                        </td>
                        <td>
                          <input v-model="r.device_type" placeholder="A96…" />
                        </td>
                        <td>
                          <input v-model="r.damage" placeholder="LCD…" />
                        </td>
                        <td>
                          <input :value="r.cost" @input="onAmountInput($event, r, 'cost')" inputmode="numeric" placeholder="0" />
                        </td>
                        <td>
                          <input :value="r.price" @input="onAmountInput($event, r, 'price')" inputmode="numeric" placeholder="0" />
                        </td>
                        <td class="muted" style="white-space:nowrap;font-weight:600">{{ formatRp(svcDraftProfit(r)) }}</td>
                        <td>
                          <input v-model="r.notes" placeholder="Opsional" />
                        </td>
                        <td>
                          <button type="button" class="btn btn-ghost btn-sm" @click="removeSvcDraftRow(r.key)">Hapus</button>
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>

                <div class="tx-draft-actions">
                  <button type="button" class="btn btn-ghost" style="width:auto" :disabled="loading" @click="addSvcDraftRow">+ Baris</button>
                  <button type="button" class="btn btn-ghost" style="width:auto" :disabled="loading" @click="resetSvcDraftRows">Kosongkan</button>
                  <div class="tx-draft-summary">
                    Siap simpan <strong>{{ svcDraftFilled.length }}</strong> baris ·
                    Profit <strong>{{ formatRp(svcDraftTotalProfit) }}</strong>
                  </div>
                  <button
                    type="button"
                    class="btn btn-primary"
                    style="width:auto;margin-left:auto"
                    :disabled="loading || !svcDraftFilled.length"
                    @click="submitSvcDraftBatch"
                  >
                    Simpan {{ svcDraftFilled.length || '' }} Servis
                  </button>
                </div>
              </div>
            </template>
          </div>

          <div class="card" :style="canInputService ? 'margin-top:14px' : ''">
            <div class="panel-title">Daftar Servis</div>
            <div class="filter-bar">
              <div v-if="isOwner" class="field">
                <label>Cabang</label>
                <select v-model="serviceFilter.branch_id" @change="loadServiceRecords(1)">
                  <option value="">Semua cabang konter</option>
                  <option v-for="b in konterBranches" :key="b.id" :value="b.id">{{ b.name }}</option>
                </select>
              </div>
              <div class="field">
                <label>Dari</label>
                <input type="date" v-model="serviceFilter.date_from" @change="loadServiceRecords(1)" />
              </div>
              <div class="field">
                <label>Sampai</label>
                <input type="date" v-model="serviceFilter.date_to" @change="loadServiceRecords(1)" />
              </div>
              <div class="field field-search">
                <label>Cari</label>
                <input v-model="serviceFilter.q" @keyup.enter="loadServiceRecords(1)" placeholder="Merek, type, kerusakan…" />
              </div>
              <div class="field field-actions">
                <label>&nbsp;</label>
                <button class="btn btn-ghost" type="button" @click="loadServiceRecords(1)">Cari</button>
              </div>
            </div>

            <div class="filter-meta">{{ serviceMeta.total || serviceSummary.jumlah || 0 }} catatan ditemukan</div>

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
                    <td class="col-no">{{ rowNo(idx, serviceMeta.current_page, serviceMeta.per_page) }}</td>
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
            <div v-if="serviceMeta.last_page > 1" class="pager">
              <button class="btn btn-ghost btn-sm" type="button" :disabled="serviceMeta.current_page <= 1 || loading" @click="loadServiceRecords(serviceMeta.current_page - 1)">Sebelumnya</button>
              <span>Halaman {{ serviceMeta.current_page }} / {{ serviceMeta.last_page }}</span>
              <button class="btn btn-ghost btn-sm" type="button" :disabled="serviceMeta.current_page >= serviceMeta.last_page || loading" @click="loadServiceRecords(serviceMeta.current_page + 1)">Berikutnya</button>
            </div>
          </div>
        </section>

        <!-- KEUNTUNGAN PULSA (konter) — Admin input; Owner pantau; PIC via tab karyawan -->
        <section v-if="page==='pulsa-profit' && canAccessPulsa && !isEmployeeRole" class="sheet-daily-page">
          <div class="page-head">
            <div>
              <h2 class="brand">Keuntungan Pulsa</h2>
              <p v-if="canInputPulsa">Hitung &amp; simpan keuntungan pulsa harian (bukan transaksi kas)</p>
              <p v-else>Pantau saja — input/ubah/hapus hanya Admin atau PIC cabang konter</p>
            </div>
          </div>

          <div class="card card-tx-form">
            <div class="tx-form-top" style="margin-bottom:12px">
              <div class="field">
                <label>Tanggal</label>
                <input type="date" v-model="pulsaDate" @change="loadPulsaDaily" />
              </div>
              <div v-if="isOwner" class="field">
                <label>Cabang</label>
                <select v-model="pulsaBranchId" @change="loadPulsaProfitPage">
                  <option disabled value="">Pilih cabang</option>
                  <option v-for="b in branches.filter(x => x.allows_service !== false)" :key="b.id" :value="b.id">{{ b.name }}</option>
                </select>
              </div>
              <div class="tx-daily-actions" style="flex-direction:row;gap:8px;align-items:flex-end">
                <button
                  v-if="canInputPulsa"
                  type="button"
                  class="btn btn-ghost"
                  @click="pulsaShowProviders = !pulsaShowProviders"
                >
                  {{ pulsaShowProviders ? 'Tutup Provider' : 'Kelola Provider' }}
                </button>
                <button
                  type="button"
                  class="btn btn-primary"
                  :disabled="loading || !pulsaBalances.length"
                  title="Bagikan ringkasan keuntungan pulsa ke WhatsApp"
                  @click="sharePulsaWhatsApp"
                >Kirim WA</button>
              </div>
            </div>

            <div v-if="pulsaShowProviders && canInputPulsa" class="card" style="margin-bottom:14px;background:var(--surface-2,#f8fafc)">
              <div class="panel-title" style="margin:0 0 8px">Provider Saldo</div>
              <p class="closing-hint" style="margin-top:0">Default DIGIPOS &amp; PAYFAZZ. Tambah provider lain sesuai cabang.</p>
              <div class="tx-form-top">
                <div class="field" style="flex:1">
                  <label>Nama provider</label>
                  <input v-model="pulsaProviderForm.name" placeholder="Contoh: DIGIPOS" @keyup.enter="submitPulsaProvider" />
                </div>
                <div style="align-self:flex-end">
                  <button type="button" class="btn btn-primary" :disabled="loading" @click="submitPulsaProvider">Tambah</button>
                </div>
              </div>
              <div class="table-wrap" style="margin-top:10px">
                <table>
                  <thead><tr><th>Nama</th><th>Status</th><th></th></tr></thead>
                  <tbody>
                    <tr v-for="p in pulsaProviders" :key="p.id">
                      <td>{{ p.name }}</td>
                      <td>{{ p.status === 'active' ? 'Aktif' : 'Nonaktif' }}</td>
                      <td>
                        <button class="btn btn-ghost btn-sm" type="button" @click="togglePulsaProvider(p)">{{ p.status === 'active' ? 'Nonaktifkan' : 'Aktifkan' }}</button>
                        <button class="btn btn-danger btn-sm" type="button" @click="deletePulsaProvider(p)">Hapus</button>
                      </td>
                    </tr>
                    <tr v-if="!pulsaProviders.length"><td colspan="3">Belum ada provider.</td></tr>
                  </tbody>
                </table>
              </div>
            </div>

            <div class="panel-title">Saldo Provider</div>
            <p class="closing-hint" style="margin-top:0">
              Saldo kemarin terisi otomatis dari hari sebelumnya. Isi saldo sekarang (dan tambah saldo jika ada).
              Terpakai = (kemarin + tambah) − sekarang.
            </p>
            <div class="table-wrap tx-draft-wrap">
              <table class="tx-draft-table">
                <thead>
                  <tr>
                    <th>Provider</th>
                    <th>Kemarin</th>
                    <th>Tambah</th>
                    <th>Sekarang</th>
                    <th>Terpakai</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="r in pulsaBalances" :key="r.pulsa_provider_id">
                    <td style="font-weight:600">{{ r.provider_name }}</td>
                    <td>
                      <input :value="r.opening_balance" @input="onAmountInput($event, r, 'opening_balance')" inputmode="numeric" :disabled="!canInputPulsa" />
                    </td>
                    <td>
                      <input :value="r.topup_amount" @input="onAmountInput($event, r, 'topup_amount')" inputmode="numeric" placeholder="0" :disabled="!canInputPulsa" />
                    </td>
                    <td>
                      <input :value="r.closing_balance" @input="onAmountInput($event, r, 'closing_balance')" inputmode="numeric" placeholder="Isi saldo" :disabled="!canInputPulsa" />
                    </td>
                    <td style="font-weight:600;white-space:nowrap">{{ formatRp(pulsaUsedOf(r)) }}</td>
                  </tr>
                  <tr v-if="!pulsaBalances.length">
                    <td colspan="5">Belum ada provider aktif. {{ canInputPulsa ? 'Tambah provider dulu.' : 'Pilih cabang konter.' }}</td>
                  </tr>
                </tbody>
              </table>
            </div>

            <div class="grid-2" style="margin-top:16px;gap:14px;align-items:start">
              <div>
                <div class="panel-title">Uang &amp; Ringkasan</div>
                <div class="form-grid">
                  <div class="field">
                    <label>Uang Pulsa (kas fisik)</label>
                    <input :value="pulsaForm.cash_on_hand" @input="onAmountInput($event, pulsaForm, 'cash_on_hand')" inputmode="numeric" placeholder="0" :disabled="!canInputPulsa" />
                  </div>
                  <div class="field">
                    <label>Catatan</label>
                    <input v-model="pulsaForm.note" placeholder="Opsional" :disabled="!canInputPulsa" />
                  </div>
                </div>
                <div class="grid-4" style="margin-top:12px">
                  <div class="card metric">
                    <div class="label">Saldo Terpotong</div>
                    <div class="value value-expense" style="font-size:1rem">{{ formatRp(pulsaTotalUsed) }}</div>
                  </div>
                  <div class="card metric">
                    <div class="label">Pengeluaran</div>
                    <div class="value" style="font-size:1rem">{{ formatRp(pulsaTotalExpense) }}</div>
                  </div>
                  <div class="card metric">
                    <div class="label">Total Uang Pulsa</div>
                    <div class="value value-income" style="font-size:1rem">{{ formatRp(pulsaTotalCash) }}</div>
                  </div>
                  <div class="card metric">
                    <div class="label">Keuntungan</div>
                    <div class="value" style="font-size:1.1rem;color:#0F766E">{{ formatRp(pulsaProfit) }}</div>
                  </div>
                </div>
              </div>

              <div>
                <div class="panel-title">Pengeluaran dari Uang Pulsa</div>
                <p class="closing-hint" style="margin-top:0">Baris kosong diabaikan. Total pengeluaran ditambahkan ke uang pulsa.</p>
                <div class="table-wrap">
                  <table class="tx-draft-table">
                    <thead><tr><th>Keterangan</th><th>Nominal</th><th style="width:64px"></th></tr></thead>
                    <tbody>
                      <tr v-for="r in pulsaExpenses" :key="r.key">
                        <td><input v-model="r.name" placeholder="HASMIN, GAS, …" :disabled="!canInputPulsa" /></td>
                        <td><input :value="r.amount" @input="onAmountInput($event, r)" inputmode="numeric" placeholder="0" :disabled="!canInputPulsa" /></td>
                        <td><button v-if="canInputPulsa" type="button" class="btn btn-ghost btn-sm" @click="removePulsaExpenseRow(r.key)">Hapus</button></td>
                      </tr>
                    </tbody>
                  </table>
                </div>
                <div v-if="canInputPulsa" style="margin-top:8px">
                  <button type="button" class="btn btn-ghost btn-sm" @click="addPulsaExpenseRow">+ Baris</button>
                </div>
              </div>
            </div>

            <div v-if="canInputPulsa" class="tx-draft-actions" style="margin-top:16px">
              <div class="tx-draft-summary">
                Keuntungan <strong>{{ formatRp(pulsaProfit) }}</strong>
              </div>
              <button type="button" class="btn btn-primary" style="width:auto;margin-left:auto" :disabled="loading || !pulsaBalances.length" @click="submitPulsaDaily">
                {{ pulsaForm.id ? 'Perbarui' : 'Simpan' }} Keuntungan Pulsa
              </button>
            </div>
          </div>

          <div class="card" style="margin-top:14px">
            <div class="panel-title">Riwayat Catatan</div>
            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Tanggal</th>
                    <th v-if="isOwner">Cabang</th>
                    <th>Saldo Terpotong</th>
                    <th>Total Uang</th>
                    <th>Keuntungan</th>
                    <th class="col-aksi">Aksi</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="r in pulsaHistory" :key="r.id">
                    <td>{{ formatDate(r.sheet_date) || r.sheet_date }}</td>
                    <td v-if="isOwner">{{ r.branch?.name }}</td>
                    <td>{{ formatRp(r.total_used_balance) }}</td>
                    <td>{{ formatRp(r.total_cash) }}</td>
                    <td style="font-weight:600;color:#0F766E">{{ formatRp(r.profit) }}</td>
                    <td class="col-aksi">
                      <button
                        class="btn btn-ghost btn-sm"
                        type="button"
                        title="Buka detail catatan tanggal ini"
                        @click="openPulsaHistoryRow(r)"
                      >Detail</button>
                      <button
                        v-if="canInputPulsa"
                        class="btn btn-danger btn-sm"
                        type="button"
                        @click="deletePulsaSheet(r)"
                      >Hapus</button>
                    </td>
                  </tr>
                  <tr v-if="!pulsaHistory.length">
                    <td :colspan="isOwner ? 6 : 5">Belum ada catatan.</td>
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
                      <button class="btn btn-ghost btn-sm" type="button" @click="openEmpAccountModal(e)">
                        {{ e.user_account ? 'Akun Login' : 'Buat Login' }}
                      </button>
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

          <div v-if="empAccountModal.open" class="modal-backdrop" @click.self="empAccountModal.open=false">
            <div class="modal">
              <h3>Akun Login — {{ empAccountModal.employee_name }}</h3>
              <p class="modal-message muted">Karyawan masuk ke BMS hanya untuk absensi (mobile).</p>
              <div class="field">
                <label>Email</label>
                <input type="email" v-model="empAccountModal.email" />
              </div>
              <div class="field">
                <label>Kata sandi {{ empAccountModal.password ? '' : '(kosongkan jika tidak diubah)' }}</label>
                <input type="text" v-model="empAccountModal.password" placeholder="Minimal 6 karakter" />
              </div>
              <div class="modal-actions">
                <button class="btn btn-ghost" type="button" @click="empAccountModal.open=false">Batal</button>
                <button class="btn btn-primary" type="button" :disabled="loading" @click="submitEmpAccount">Simpan</button>
              </div>
            </div>
          </div>
        </section>

        <!-- TARGET CLOSINGAN (owner + admin konter) -->
        <section v-if="page==='closings' && canAccessClosings">
          <div class="page-head">
            <div>
              <h2 class="brand">Closing Harian &amp; Target</h2>
              <p>Input closing harian &amp; target bulanan karyawan konter (non-promotor)</p>
            </div>
          </div>

          <div v-if="!isOwner && closingIsLocked" class="banner-lock">
            Target closingan periode ini telah dikunci oleh Owner. Anda tidak dapat mengubah data.
            <span v-if="closingBoard.meta?.locked_at"> ({{ closingBoard.meta.locked_at }})</span>
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
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                  <button class="btn btn-ghost" type="button" :disabled="loading" @click="loadClosingBoard">Muat Ulang</button>
                  <button
                    v-if="isOwner && closingFilter.branch_id"
                    class="btn"
                    :class="closingIsLocked ? 'btn-ghost' : 'btn-primary'"
                    type="button"
                    :disabled="loading"
                    @click="lockClosingBoard(closingFilter.branch_id, !closingIsLocked)"
                  >{{ closingIsLocked ? 'Buka Kunci' : 'Kunci' }}</button>
                </div>
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
              <span v-if="closingFilter.branch_id || !isOwner">
                · Status:
                <strong :style="{ color: closingIsLocked ? '#EF4444' : '#10B981' }">
                  {{ closingIsLocked ? 'Terkunci' : 'Terbuka' }}
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
                        <div style="display:flex;flex-wrap:wrap;align-items:center;gap:10px;justify-content:space-between">
                          <div>
                            <strong>{{ g.branch_name }}</strong>
                            <span class="muted"> · subtotal {{ g.branch_total }}</span>
                            <span class="muted" :style="{ color: g.is_locked ? '#EF4444' : undefined }">
                              · {{ g.is_locked ? 'Terkunci' : 'Terbuka' }}
                            </span>
                          </div>
                          <button
                            class="btn btn-sm"
                            :class="g.is_locked ? 'btn-ghost' : 'btn-primary'"
                            type="button"
                            :disabled="loading || !g.branch_id"
                            @click="lockClosingBoard(g.branch_id, !g.is_locked)"
                          >{{ g.is_locked ? 'Buka Kunci' : 'Kunci' }}</button>
                        </div>
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
                          :disabled="!canEditClosingRow(row)"
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
                          :disabled="!canEditClosingRow(row)"
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
              Target default = jumlah hari pada bulan ini (mis. Juli = 31), bisa diubah manual lalu Enter/klik luar untuk simpan.
              Setiap perubahan akan diminta konfirmasi. Esc membatalkan sebelum konfirmasi. Baris hijau = total harian per cabang.
              Owner dapat mengunci periode closingan agar Admin cabang tidak bisa mengubah qty/target.
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

          <div v-if="isOwner" class="card" style="margin-bottom:14px">
            <div class="panel-title">Jam Absen Mandiri (per cabang)</div>
            <div class="filter-bar">
              <div class="field">
                <label>Cabang</label>
                <select v-model="attSettingsForm.branch_id" @change="loadAttSettings">
                  <option disabled value="">Pilih cabang</option>
                  <option v-for="b in branches" :key="'ats'+b.id" :value="b.id">{{ b.name }}</option>
                </select>
              </div>
              <div class="field"><label>Masuk dari</label><input type="time" v-model="attSettingsForm.check_in_start" /></div>
              <div class="field"><label>Masuk sampai</label><input type="time" v-model="attSettingsForm.check_in_end" /></div>
              <div class="field"><label>Pulang dari</label><input type="time" v-model="attSettingsForm.check_out_start" /></div>
              <div class="field"><label>Pulang sampai</label><input type="time" v-model="attSettingsForm.check_out_end" /></div>
              <div class="field field-actions">
                <label>&nbsp;</label>
                <button class="btn btn-primary btn-sm" type="button" :disabled="loading || !attSettingsForm.branch_id" @click="saveAttSettings">Simpan Jam</button>
              </div>
            </div>
          </div>

          <div v-if="isOwner" class="card" style="margin-bottom:14px">
            <div class="panel-title" style="display:flex;justify-content:space-between;align-items:center">
              <span>Antrean Absen Tidak Lengkap</span>
              <button class="btn btn-ghost btn-sm" type="button" @click="loadAttReviews">Muat</button>
            </div>
            <div class="table-wrap">
              <table>
                <thead><tr><th>Tanggal</th><th>Cabang</th><th>Nama</th><th>Masuk</th><th>Aksi</th></tr></thead>
                <tbody>
                  <tr v-for="r in attReviewRows" :key="'ar'+r.id">
                    <td>{{ r.date }}</td>
                    <td>{{ r.branch_name }}</td>
                    <td>{{ r.employee_name }}</td>
                    <td>{{ r.check_in_at ? formatDateTime(r.check_in_at) : '—' }}</td>
                    <td>
                      <button class="btn btn-primary btn-sm" type="button" @click="reviewAttendance(r, 'approve')">Setujui</button>
                      <button class="btn btn-danger btn-sm" type="button" @click="reviewAttendance(r, 'reject')">Tolak</button>
                    </td>
                  </tr>
                  <tr v-if="!attReviewRows.length"><td colspan="5">Tidak ada antrean (klik Muat).</td></tr>
                </tbody>
              </table>
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
                    <td :colspan="isOwner && !attendanceFilter.branch_id ? 5 : 4">Tidak ada karyawan aktif (Owner disembunyikan).</td>
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
              <p>Rekap bulanan konter: Gapok, Insentif HP/ACC, Service, bonus, hutang &amp; kasbon</p>
            </div>
          </div>

          <div class="card">
            <div class="filter-bar payroll-filter-bar">
              <div class="payroll-filter-fields">
                <div v-if="isOwner" class="field">
                  <label>Cabang</label>
                  <select v-model="payrollFilter.branch_id" @change="onPayrollFilterChange">
                    <option value="">Semua cabang konter</option>
                    <option v-for="b in konterBranches" :key="b.id" :value="b.id">{{ b.name }}</option>
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
              </div>
              <div class="field field-actions payroll-actions-field">
                <label>&nbsp;</label>
                <div class="payroll-actions">
                  <button class="btn btn-primary btn-sm" type="button" :disabled="loading || payrollBoard.meta?.all_locked" @click="savePayrollBoard">Simpan</button>
                  <button class="btn btn-danger btn-sm" type="button" :disabled="loading || payrollBoard.meta?.all_locked" @click="lockPayrollBoard">Kunci</button>
                  <button v-if="isOwner && payrollBoard.meta?.any_locked" class="btn btn-ghost btn-sm" type="button" :disabled="loading" @click="unlockPayrollBoard">Buka Kunci</button>
                  <button class="btn btn-ghost btn-sm" type="button" title="Muat ulang dari absensi, closing, service, kasbon, dan usulan PIC Bagi Hasil" :disabled="loading" @click="loadPayrollBoard">Hitung Ulang</button>
                </div>
              </div>
            </div>

            <div class="filter-meta">
              {{ payrollMonthLabel }}
              · Total bersih: <strong :class="Number(payrollBoard.meta?.totals?.grand_total || 0) < 0 ? 'value-expense' : ''">{{ formatRp(payrollBoard.meta?.totals?.grand_total || 0) }}</strong>
              · Gapok <strong>{{ formatRp(payrollBoard.meta?.totals?.gapok || 0) }}</strong>
              · PIC <strong>{{ formatRp(payrollBoard.meta?.totals?.insentif_pic || 0) }}</strong>
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
                    <th title="Hari hadir">Hadir</th>
                    <th title="Gaji pokok">Gapok</th>
                    <th title="Insentif PIC (manual / dari Bagi Hasil). Hanya jabatan PIC.">PIC</th>
                    <th title="Qty closing HP">Qty</th>
                    <th title="Insentif HP">HP</th>
                    <th title="Insentif service 50%">Svc</th>
                    <th title="Insentif ACC">ACC</th>
                    <th>Bonus</th>
                    <th title="Diisi manual">Hutang</th>
                    <th title="Otomatis dari transaksi kategori Kasbon (semua cabang)">Kasbon</th>
                    <th>Total</th>
                    <th>Status</th>
                    <th>Bayar</th>
                    <th>Aksi</th>
                  </tr>
                </thead>
                <tbody>
                  <template v-for="(g, gi) in payrollBoard.groups" :key="'pg'+gi">
                    <tr v-if="isOwner && !payrollFilter.branch_id" class="closing-group-row">
                      <td :colspan="isOwner && !payrollFilter.branch_id ? 17 : 16">
                        <strong>{{ g.branch_name }}</strong>
                        <span class="muted"> · <span :class="Number(g.totals?.grand_total || 0) < 0 ? 'value-expense' : ''">{{ formatRp(g.totals?.grand_total || 0) }}</span></span>
                      </td>
                    </tr>
                    <tr v-for="row in g.rows" :key="'pr'+row.employee_id">
                      <td class="col-sticky"><strong>{{ row.name }}</strong></td>
                      <td v-if="isOwner && !payrollFilter.branch_id" class="col-sticky-2">{{ row.branch_name }}</td>
                      <td>{{ row.position || '—' }}</td>
                      <td>{{ row.present_days }}</td>
                      <td>
                        <input
                          class="payroll-cell"
                          type="text"
                          inputmode="numeric"
                          title="Dihitung otomatis (hadir × Rp50.000; promotor 0). Bisa diubah manual."
                          :disabled="row.status==='locked' || loading"
                          :value="formatInputNumber(row.gapok)"
                          @focus="onPayrollFocus"
                          @keydown="onPayrollKeydown"
                          @input="onPayrollManualInput(row, 'gapok', $event)"
                        />
                      </td>
                      <td>
                        <div class="payroll-pic-cell">
                          <input
                            class="payroll-cell"
                            type="text"
                            inputmode="numeric"
                            :title="row.is_pic
                              ? ('Insentif PIC. Usulan Bagi Hasil: ' + formatRp(row.insentif_pic_auto || 0))
                              : 'Hanya untuk jabatan PIC'"
                            :disabled="!row.is_pic || row.status==='locked' || loading"
                            :value="formatInputNumber(row.is_pic ? row.insentif_pic : 0)"
                            @focus="onPayrollFocus"
                            @keydown="onPayrollKeydown"
                            @input="onPayrollManualInput(row, 'insentif_pic', $event)"
                          />
                          <button
                            v-if="row.is_pic && row.status !== 'locked'"
                            type="button"
                            class="btn btn-ghost btn-xs"
                            title="Isi dari bagian PIC di Bagi Hasil"
                            :disabled="loading || !(Number(row.insentif_pic_auto || 0) > 0)"
                            @click="applyPayrollPicFromBagiHasil(row)"
                          >BH</button>
                        </div>
                      </td>
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
                      <td class="value-expense" :title="'Total transaksi Kasbon karyawan (semua cabang)'">
                        {{ formatRp(row.kasbon != null ? row.kasbon : row.pengeluaran) }}
                      </td>
                      <td><strong :class="Number(row.total) < 0 ? 'value-expense' : ''">{{ formatRp(row.total) }}</strong></td>
                      <td>
                        <span class="badge" :class="row.status==='locked' ? 'badge-rejected' : 'badge-approved'">
                          {{ row.status==='locked' ? 'Terkunci' : 'Draf' }}
                        </span>
                      </td>
                      <td>
                        <span
                          v-if="row.status==='locked'"
                          class="badge"
                          :class="row.is_paid ? 'badge-approved' : 'badge-pending'"
                          :title="row.is_paid && row.paid_at ? ('Dibayar: ' + formatDateTime(row.paid_at)) : ''"
                        >{{ row.is_paid ? 'Lunas' : 'Belum' }}</span>
                        <span v-else class="muted">—</span>
                      </td>
                      <td>
                        <div class="payroll-row-actions">
                          <button class="btn btn-ghost btn-sm" type="button" @click="openPayrollDetail(row)">Detail</button>
                          <button
                            class="btn btn-ghost btn-sm"
                            type="button"
                            title="Buka WhatsApp ke nomor karyawan"
                            :disabled="!row.phone"
                            @click="openPayrollWhatsApp(row)"
                          >WA</button>
                          <button
                            v-if="row.status==='locked' && !row.is_paid"
                            class="btn btn-primary btn-sm"
                            type="button"
                            :disabled="loading"
                            @click="markPayrollPaid(row)"
                          >Bayar</button>
                          <button
                            v-if="row.status==='locked' && row.is_paid"
                            class="btn btn-ghost btn-sm"
                            type="button"
                            :disabled="loading"
                            @click="markPayrollUnpaid(row)"
                          >Batal</button>
                        </div>
                      </td>
                    </tr>
                    <tr class="closing-subtotal-row">
                      <td class="col-sticky"><strong>Total {{ g.branch_name }}</strong></td>
                      <td v-if="isOwner && !payrollFilter.branch_id" class="col-sticky-2">—</td>
                      <td colspan="2">—</td>
                      <td><strong>{{ formatRp(g.totals?.gapok || 0) }}</strong></td>
                      <td><strong>{{ formatRp(g.totals?.insentif_pic || 0) }}</strong></td>
                      <td>{{ g.totals?.closing_qty || 0 }}</td>
                      <td><strong>{{ formatRp(g.totals?.insentif_hp || 0) }}</strong></td>
                      <td><strong>{{ formatRp(g.totals?.service_incentive || 0) }}</strong></td>
                      <td><strong>{{ formatRp(g.totals?.insentif_acc || 0) }}</strong></td>
                      <td><strong>{{ formatRp(g.totals?.bonus_absen || 0) }}</strong></td>
                      <td><strong>{{ formatRp(g.totals?.hutang || 0) }}</strong></td>
                      <td><strong>{{ formatRp(g.totals?.pengeluaran || 0) }}</strong></td>
                      <td><strong :class="Number(g.totals?.grand_total || 0) < 0 ? 'value-expense' : ''">{{ formatRp(g.totals?.grand_total || 0) }}</strong></td>
                      <td colspan="3"></td>
                    </tr>
                  </template>
                  <tr v-if="!(payrollBoard.groups || []).length">
                    <td :colspan="isOwner && !payrollFilter.branch_id ? 17 : 16">
                      Belum ada karyawan aktif untuk periode ini.
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
            <p class="closing-hint">
              Gapok = Hadir × Rp50.000 (promotor = 0), bisa diubah manual lalu Simpan.
              PIC = insentif PIC (hanya jabatan PIC), bisa diisi manual atau tombol BH dari Bagi Hasil.
              HP = closing × Rp10.000. Svc = 50% profit service (teknisi). ACC/Bonus/Hutang diisi manual.
              Kasbon dihitung otomatis dari transaksi kategori Kasbon (semua cabang) pada bulan yang sama.
              Total = Gapok + PIC + HP + Service + ACC + Bonus − Hutang − Kasbon.
              Status bayar (Belum/Lunas) hanya setelah slip dikunci.
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
            <button class="tab-btn" :class="{active: wwTab==='daily'}" @click="switchWwTab('daily')">1. Harian</button>
            <button class="tab-btn" :class="{active: wwTab==='weekly'}" @click="switchWwTab('weekly')">2. Mingguan</button>
            <button class="tab-btn" :class="{active: wwTab==='job-types'}" @click="switchWwTab('job-types')">3. Jenis Kerja</button>
            <button class="tab-btn" :class="{active: wwTab==='settings'}" @click="switchWwTab('settings')">4. Pengaturan %</button>
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
              <template v-if="wwTab==='weekly' || wwTab==='settings'">
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
                <label>Tanggal kerja</label>
                <input type="date" v-model="wwDailyDate" @change="onWwDailyDateChange" />
              </div>
            </div>

            <div v-if="isOwner && !wwFilter.branch_id" class="empty-hint">
              Pilih cabang bengkel untuk melanjutkan.
            </div>

            <template v-else-if="wwTab==='daily'">
              <div class="ww-daily">
                <div class="ww-daily-step">
                  <div class="ww-daily-step-label">Langkah 1</div>
                  <div class="ww-daily-date-line">
                    <strong>{{ formatWwDateLabel(wwDailyDate) }}</strong>
                    <span class="muted">Satu tanggal untuk semua baris di bawah</span>
                  </div>
                </div>

                <div v-if="wwJobForm.id" class="ww-edit-banner">
                  <div class="panel-title" style="margin:0">Ubah kerja tersimpan</div>
                  <div class="ww-draft-grid ww-edit-grid">
                    <div class="field">
                      <label>Jenis kerja</label>
                      <select v-model="wwJobForm.job_type" @change="applyWwJobTypeDefault(wwJobForm)">
                        <option v-for="jt in wwJobTypes" :key="'edit-jt-'+jt.id" :value="jt.name">{{ jt.name }}</option>
                        <option v-if="wwJobForm.job_type && !wwJobTypes.some(t => t.name === wwJobForm.job_type)" :value="wwJobForm.job_type">{{ wwJobForm.job_type }}</option>
                      </select>
                    </div>
                    <div class="field">
                      <label>Teknisi</label>
                      <select v-model="wwJobForm.employee_id">
                        <option disabled value="">Pilih teknisi</option>
                        <option v-for="t in wwTechnicians" :key="'edit-'+t.id" :value="t.id">{{ t.name }}</option>
                      </select>
                    </div>
                    <div class="field">
                      <label>Nominal</label>
                      <input :value="wwJobForm.amount" @input="onAmountInput($event, wwJobForm)" inputmode="numeric" placeholder="0" />
                    </div>
                    <div class="field">
                      <label>Catatan</label>
                      <input v-model="wwJobForm.note" placeholder="Opsional" />
                    </div>
                  </div>
                  <div class="ww-daily-actions">
                    <button class="btn btn-success" style="width:auto" :disabled="loading" @click="submitWwJob">Simpan perubahan</button>
                    <button class="btn btn-ghost" style="width:auto" @click="resetWwJobForm">Batal</button>
                  </div>
                </div>

                <div v-else class="ww-daily-step">
                  <div class="ww-daily-step-head">
                    <div>
                      <div class="ww-daily-step-label">Langkah 2 — Multi input</div>
                      <div class="panel-title" style="margin:0">Tambah beberapa kerja sekaligus</div>
                      <p class="closing-hint" style="margin:6px 0 0">Urutan seperti spreadsheet: jenis pekerjaan → teknisi → nominal. Baris kosong diabaikan.</p>
                    </div>
                    <div class="ww-chip-row">
                      <button
                        v-for="jt in wwJobTypes"
                        :key="'chip-'+jt.id"
                        type="button"
                        class="ww-chip"
                        @click="addWwDraftRow(jt.name)"
                      >+ {{ jt.name }}</button>
                    </div>
                  </div>

                  <div class="table-wrap ww-draft-wrap">
                    <table class="ww-draft-table">
                      <thead>
                        <tr>
                          <th style="width:40px">#</th>
                          <th>Jenis pekerjaan</th>
                          <th>Teknisi</th>
                          <th>Nominal</th>
                          <th>Catatan</th>
                          <th style="width:56px"></th>
                        </tr>
                      </thead>
                      <tbody>
                        <tr v-for="(r, idx) in wwDraftRows" :key="r.key">
                          <td class="muted">{{ idx + 1 }}</td>
                          <td>
                            <select v-model="r.job_type" @change="applyWwJobTypeDefault(r)">
                              <option disabled value="">Pilih jenis</option>
                              <option v-for="jt in wwJobTypes" :key="r.key+'-jt-'+jt.id" :value="jt.name">
                                {{ jt.name }}{{ jt.default_amount != null ? ' · ' + formatRp(jt.default_amount) : '' }}
                              </option>
                            </select>
                          </td>
                          <td>
                            <select v-model="r.employee_id">
                              <option value="">Pilih teknisi</option>
                              <option v-for="t in wwTechnicians" :key="r.key+'-'+t.id" :value="t.id">{{ t.name }}</option>
                            </select>
                          </td>
                          <td>
                            <input :value="r.amount" @input="onAmountInput($event, r)" inputmode="numeric" placeholder="0" />
                          </td>
                          <td>
                            <input v-model="r.note" placeholder="Opsional" />
                          </td>
                          <td>
                            <button type="button" class="btn btn-ghost btn-sm" @click="removeWwDraftRow(r.key)">Hapus</button>
                          </td>
                        </tr>
                      </tbody>
                    </table>
                  </div>

                  <div class="ww-daily-actions">
                    <button type="button" class="btn btn-ghost" style="width:auto" :disabled="loading" @click="addWwDraftRow()">+ Baris</button>
                    <button type="button" class="btn btn-ghost" style="width:auto" :disabled="loading" @click="resetWwDraftRows">Kosongkan</button>
                    <div class="ww-daily-summary">
                      Siap simpan <strong>{{ wwDraftFilled.length }}</strong> baris ·
                      Total <strong>{{ formatRp(wwDraftTotal) }}</strong>
                    </div>
                    <button
                      type="button"
                      class="btn btn-primary"
                      style="width:auto;margin-left:auto"
                      :disabled="loading || !wwDraftFilled.length"
                      @click="submitWwDraftBatch"
                    >
                      Simpan {{ wwDraftFilled.length || '' }} Kerja
                    </button>
                  </div>
                </div>

                <div class="ww-daily-step">
                  <div class="ww-daily-step-head">
                    <div>
                      <div class="ww-daily-step-label">Langkah 3 — Tersimpan</div>
                      <div class="panel-title" style="margin:0">Kerja {{ formatWwDateLabel(wwDailyDate) }}</div>
                    </div>
                    <div class="ww-daily-summary">
                      {{ wwJobs.length }} pekerjaan · <strong>{{ formatRp(wwJobsDayTotal) }}</strong>
                    </div>
                  </div>
                  <div class="table-wrap">
                    <table>
                      <thead>
                        <tr>
                          <th>Jenis</th>
                          <th>Teknisi</th>
                          <th>Nominal</th>
                          <th>Catatan</th>
                          <th></th>
                        </tr>
                      </thead>
                      <tbody>
                        <tr v-for="j in wwJobs" :key="j.id">
                          <td><strong>{{ j.job_type }}</strong></td>
                          <td>{{ j.employee_name }}</td>
                          <td>{{ formatRp(j.amount) }}</td>
                          <td class="muted">{{ j.note || '—' }}</td>
                          <td class="ww-job-actions">
                            <button class="btn btn-ghost btn-sm" @click="editWwJob(j)">Ubah</button>
                            <button class="btn btn-danger btn-sm" @click="deleteWwJob(j)">Hapus</button>
                          </td>
                        </tr>
                        <tr v-if="!wwJobs.length">
                          <td colspan="5">Belum ada kerja di tanggal ini. Isi multi input di atas lalu simpan.</td>
                        </tr>
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

            <template v-else-if="wwTab==='job-types'">
              <p class="closing-hint">
                Kelola daftar jenis pekerjaan per cabang. Nominal default opsional — terisi otomatis saat input harian, tetap bisa diubah per baris.
              </p>
              <div class="grid-2">
                <div>
                  <div class="panel-title">{{ wwJobTypeForm.id ? 'Ubah Jenis Kerja' : 'Tambah Jenis Kerja' }}</div>
                  <div class="form-grid">
                    <div class="field">
                      <label>Nama jenis</label>
                      <input v-model="wwJobTypeForm.name" placeholder="Contoh: GANTI OLI" />
                    </div>
                    <div class="field">
                      <label>Nominal default <span class="opt">(opsional)</span></label>
                      <input
                        :value="wwJobTypeForm.default_amount"
                        @input="onAmountInput($event, wwJobTypeForm, 'default_amount')"
                        inputmode="numeric"
                        placeholder="Kosongkan jika bervariasi"
                      />
                    </div>
                    <div class="field">
                      <label>Urutan</label>
                      <input type="number" min="0" v-model.number="wwJobTypeForm.sort_order" />
                    </div>
                    <div class="field" v-if="wwJobTypeForm.id">
                      <label>Status</label>
                      <select v-model="wwJobTypeForm.status">
                        <option value="active">Aktif</option>
                        <option value="inactive">Nonaktif</option>
                      </select>
                    </div>
                    <div style="display:flex;gap:8px;flex-wrap:wrap">
                      <button class="btn btn-primary" style="width:auto" :disabled="loading" @click="submitWwJobType">
                        {{ wwJobTypeForm.id ? 'Simpan Perubahan' : 'Tambah Jenis' }}
                      </button>
                      <button v-if="wwJobTypeForm.id" class="btn btn-ghost" style="width:auto" type="button" @click="resetWwJobTypeForm">Batal</button>
                    </div>
                  </div>
                </div>
                <div>
                  <div class="panel-title">Daftar jenis ({{ wwJobTypeCatalog.length }})</div>
                  <div class="table-wrap">
                    <table>
                      <thead>
                        <tr>
                          <th>Jenis</th>
                          <th>Default</th>
                          <th>Status</th>
                          <th></th>
                        </tr>
                      </thead>
                      <tbody>
                        <tr v-for="jt in wwJobTypeCatalog" :key="jt.id">
                          <td><strong>{{ jt.name }}</strong></td>
                          <td>{{ jt.default_amount != null ? formatRp(jt.default_amount) : '—' }}</td>
                          <td>
                            <span class="badge" :class="jt.status==='active' ? 'badge-approved' : 'badge-pending'">
                              {{ jt.status==='active' ? 'Aktif' : 'Nonaktif' }}
                            </span>
                          </td>
                          <td class="ww-job-actions">
                            <button class="btn btn-ghost btn-sm" type="button" @click="editWwJobType(jt)">Ubah</button>
                            <button class="btn btn-ghost btn-sm" type="button" @click="toggleWwJobTypeStatus(jt)">
                              {{ jt.status==='active' ? 'Nonaktifkan' : 'Aktifkan' }}
                            </button>
                            <button class="btn btn-danger btn-sm" type="button" @click="deleteWwJobType(jt)">Hapus</button>
                          </td>
                        </tr>
                        <tr v-if="!wwJobTypeCatalog.length">
                          <td colspan="4">Belum ada jenis kerja. Tambah di formulir kiri.</td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>
            </template>

            <template v-else-if="wwTab==='settings'">
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
              <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap">
                <button class="btn btn-success" style="width:auto" :disabled="loading || !wwSettingsRows.length" @click="saveWwSettings">
                  Simpan Persen Per Teknisi
                </button>
                <button class="btn btn-ghost" style="width:auto" :disabled="loading || !wwSettingsRows.length" @click="copyWwSettingsFromPrevious">
                  Salin dari {{ wwPreviousMonthLabel() }}
                </button>
              </div>
            </template>
          </div>
        </section>

        <!-- LAPORAN UPAH (menu Bengkel — Owner + Admin bengkel) -->
        <section v-if="page==='workshop-upah-report' && canAccessWorkshopUpahReport">
          <div class="page-head">
            <div>
              <h2 class="brand">Laporan Upah Kerja</h2>
              <p>Ringkasan job &amp; upah teknisi bengkel per periode</p>
            </div>
          </div>

          <div class="card">
            <div class="panel-title">Filter</div>
            <div class="filter-bar">
              <div v-if="isOwner" class="field">
                <label>Cabang</label>
                <select v-model="reportForm.branch_id" @change="reportForm.employee_id=''; loadWorkshopUpahReport()">
                  <option value="">Semua cabang bengkel</option>
                  <option v-for="b in workshopBranches" :key="b.id" :value="b.id">{{ b.name }}</option>
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
              <div class="field">
                <label>Teknisi</label>
                <select v-model="reportForm.employee_id">
                  <option value="">Semua teknisi</option>
                  <option v-for="e in reportUpahTechnicians" :key="e.id" :value="e.id">{{ reportTechLabel(e) }}</option>
                </select>
              </div>
              <div class="field field-actions">
                <label>&nbsp;</label>
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                  <button class="btn btn-primary" type="button" :disabled="loading" @click="loadWorkshopUpahReport">Tampilkan</button>
                  <button class="btn btn-ghost" type="button" :disabled="loading" @click="reportForm.type='upah'; exportReportPdf('attachment')">Export PDF</button>
                  <button class="btn btn-ghost" type="button" :disabled="loading" @click="reportForm.type='upah'; exportReportPdf('inline')">Buka PDF</button>
                </div>
              </div>
            </div>
          </div>

          <div v-if="reportResult && reportForm.type==='upah'" class="card" style="margin-top:14px">
            <div class="panel-title">{{ reportResult.meta?.judul || 'Hasil Laporan' }}</div>
            <div class="filter-meta" style="margin-bottom:12px">
              {{ reportResult.meta?.cabang }} · {{ reportResult.meta?.periode }}
              · {{ reportResult.data?.jumlah || 0 }} job
              · Gross {{ formatRp(reportResult.data?.total_gross) }}
              · Upah {{ formatRp(reportResult.data?.total_net) }}
              · Toko {{ formatRp(reportResult.data?.total_shop) }}
            </div>

            <div class="report-kpi-row" style="margin-bottom:14px">
              <div class="report-kpi">
                <span class="report-kpi-label">Job</span>
                <strong>{{ reportResult.data?.jumlah || 0 }}</strong>
              </div>
              <div class="report-kpi">
                <span class="report-kpi-label">Gross</span>
                <strong class="value-income">{{ formatRp(reportResult.data?.total_gross) }}</strong>
              </div>
              <div class="report-kpi">
                <span class="report-kpi-label">Upah teknisi</span>
                <strong>{{ formatRp(reportResult.data?.total_net) }}</strong>
              </div>
              <div class="report-kpi">
                <span class="report-kpi-label">Bagian toko</span>
                <strong>{{ formatRp(reportResult.data?.total_shop) }}</strong>
              </div>
            </div>

            <div class="panel-title" style="margin-bottom:8px">Ringkasan per Teknisi</div>
            <div class="table-wrap" style="margin-bottom:16px">
              <table>
                <thead>
                  <tr>
                    <th class="col-no">No</th>
                    <th>Teknisi</th>
                    <th>Cabang</th>
                    <th>Job</th>
                    <th>Gross</th>
                    <th>% Upah</th>
                    <th>Upah</th>
                    <th>% Toko</th>
                    <th>Toko</th>
                    <th class="col-aksi">Aksi</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(row, idx) in (reportResult.data?.by_teknisi || [])" :key="'wur'+row.employee_id">
                    <td class="col-no">{{ rowNo(idx) }}</td>
                    <td><strong>{{ row.teknisi }}</strong></td>
                    <td>{{ row.cabang }}</td>
                    <td>{{ row.jumlah_job }}</td>
                    <td class="value-income">{{ formatRp(row.total_gross) }}</td>
                    <td>{{ formatPctShare(row.tech_share_pct) }}{{ row.pct_mixed ? '*' : '' }}</td>
                    <td><strong>{{ formatRp(row.total_net) }}</strong></td>
                    <td>{{ formatPctShare(row.shop_share_pct) }}{{ row.pct_mixed ? '*' : '' }}</td>
                    <td>{{ formatRp(row.total_shop) }}</td>
                    <td class="col-aksi">
                      <button class="btn btn-ghost btn-sm" type="button" @click="openReportUpahTechDetail(row)">Detail</button>
                    </td>
                  </tr>
                  <tr v-if="!(reportResult.data?.by_teknisi || []).length">
                    <td colspan="10">Tidak ada data upah di periode ini.</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </section>

        <section v-if="page==='kelola' && isOwner" class="kelola-page">
          <div class="page-head kelola-head">
            <div>
              <h2 class="brand">Kelola</h2>
              <p>Pengaturan master data sistem — cabang, akun, admin, dan kategori</p>
            </div>
          </div>
          <div class="kelola-tabs" role="tablist" aria-label="Menu kelola">
            <button type="button" role="tab" class="kelola-tab" :class="{active: kelolaTab==='branches'}" :aria-selected="kelolaTab==='branches'" @click="kelolaTab='branches'">Cabang</button>
            <button type="button" role="tab" class="kelola-tab" :class="{active: kelolaTab==='accounts'}" :aria-selected="kelolaTab==='accounts'" @click="kelolaTab='accounts'; loadAllAccounts()">Akun</button>
            <button type="button" role="tab" class="kelola-tab" :class="{active: kelolaTab==='admins'}" :aria-selected="kelolaTab==='admins'" @click="kelolaTab='admins'">Admin</button>
            <button type="button" role="tab" class="kelola-tab" :class="{active: kelolaTab==='categories'}" :aria-selected="kelolaTab==='categories'" @click="kelolaTab='categories'">Kategori</button>
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

          <div v-if="kelolaTab==='categories'" class="kelola-stack">
            <div class="card kelola-form-card">
              <div class="kelola-card-head">
                <div>
                  <div class="panel-title">{{ categoryForm.id ? 'Ubah Kategori' : 'Tambah Kategori' }}</div>
                  <p class="kelola-card-desc">
                    Atur nama, tipe, dan cakupan. Kategori sistem (Transfer/Penyesuaian) tidak bisa diubah.
                  </p>
                </div>
                <span v-if="categoryForm.id" class="badge badge-pending">Mode edit</span>
              </div>
              <div class="kelola-form-row">
                <div class="field">
                  <label>Nama kategori</label>
                  <input v-model="categoryForm.name" placeholder="Contoh: Belanja Tools" />
                </div>
                <div class="field">
                  <label>Cakupan</label>
                  <select v-model="categoryForm.branch_id">
                    <option value="">Global (semua cabang)</option>
                    <option v-for="b in branches" :key="b.id" :value="b.id">Lokal: {{ b.name }}</option>
                  </select>
                </div>
                <div class="field field-type">
                  <label>Tipe transaksi</label>
                  <div class="type-toggle">
                    <button
                      type="button"
                      class="type-toggle-btn income"
                      :class="{active: categoryForm.type==='income'}"
                      :disabled="!!categoryForm.id"
                      @click="categoryForm.type='income'"
                    >Pemasukan</button>
                    <button
                      type="button"
                      class="type-toggle-btn expense"
                      :class="{active: categoryForm.type==='expense'}"
                      :disabled="!!categoryForm.id"
                      @click="categoryForm.type='expense'"
                    >Pengeluaran</button>
                  </div>
                </div>
                <div v-if="categoryForm.id" class="field">
                  <label>Status</label>
                  <select :value="categoryForm.is_active ? '1' : '0'" @change="categoryForm.is_active = ($event.target.value === '1')">
                    <option value="1">Aktif</option>
                    <option value="0">Nonaktif</option>
                  </select>
                </div>
                <div class="kelola-form-actions">
                  <button class="btn btn-primary" type="button" :disabled="loading" @click="submitCategory">
                    {{ categoryForm.id ? 'Perbarui Kategori' : 'Simpan Kategori' }}
                  </button>
                  <button v-if="categoryForm.id" class="btn btn-ghost" type="button" @click="resetCategoryForm">Batal</button>
                </div>
              </div>
            </div>

            <div class="card kelola-list-card">
              <div class="kelola-card-head">
                <div>
                  <div class="panel-title">Daftar Kategori</div>
                  <p class="kelola-card-desc">
                    {{ kelolaCategoriesFiltered.length }} dari {{ categories.length }} kategori
                    <template v-if="kelolaCategoryQuery.trim()"> (hasil pencarian)</template>
                  </p>
                </div>
                <div class="kelola-toolbar">
                  <div class="field field-search">
                    <label>Cari</label>
                    <input
                      v-model="kelolaCategoryQuery"
                      type="search"
                      placeholder="Nama, tipe, cakupan…"
                      autocomplete="off"
                    />
                  </div>
                </div>
              </div>
              <div class="table-wrap">
                <table class="kelola-table">
                  <thead>
                    <tr>
                      <th class="col-no">No</th>
                      <th>Nama</th>
                      <th>Tipe</th>
                      <th>Cakupan</th>
                      <th>Status</th>
                      <th class="col-aksi">Aksi</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="(c, idx) in kelolaCategoriesPaged" :key="c.id">
                      <td class="col-no">{{ rowNo(idx, kelolaCategoryPage, kelolaCategoryPerPage) }}</td>
                      <td><strong class="kelola-name">{{ c.name }}</strong></td>
                      <td>
                        <span class="type-chip" :class="c.type==='income' ? 'is-income' : 'is-expense'">
                          {{ c.type==='income' ? 'Pemasukan' : 'Pengeluaran' }}
                        </span>
                      </td>
                      <td>
                        <span class="scope-chip" :class="c.branch_id ? 'is-local' : 'is-global'">
                          {{ categoryScopeLabel(c) }}
                        </span>
                      </td>
                      <td>
                        <span class="badge" :class="c.is_active !== false ? 'badge-approved' : 'badge-rejected'">
                          {{ c.is_active !== false ? 'Aktif' : 'Nonaktif' }}
                        </span>
                      </td>
                      <td class="col-aksi">
                        <template v-if="canManageCategory(c)">
                          <div class="aksi-group">
                            <button class="btn btn-ghost btn-sm" type="button" @click="editCategory(c)">Edit</button>
                            <button class="btn btn-ghost btn-sm" type="button" @click="toggleCategoryActive(c)">
                              {{ c.is_active !== false ? 'Nonaktifkan' : 'Aktifkan' }}
                            </button>
                            <button class="btn btn-danger btn-sm" type="button" @click="deleteCategory(c)">Hapus</button>
                          </div>
                        </template>
                        <span v-else class="muted" style="font-size:.8rem">
                          {{ isSystemCategory(c) ? 'Sistem' : '—' }}
                        </span>
                      </td>
                    </tr>
                    <tr v-if="!kelolaCategoriesFiltered.length">
                      <td colspan="6" class="empty-hint">
                        {{ kelolaCategoryQuery.trim() ? 'Tidak ada kategori yang cocok.' : 'Belum ada kategori.' }}
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <div v-if="kelolaCategoryPageCount > 1" class="pager">
                <button
                  class="btn btn-ghost btn-sm"
                  type="button"
                  :disabled="kelolaCategoryPage <= 1"
                  @click="kelolaCategoryPage--"
                >Sebelumnya</button>
                <span>Halaman {{ kelolaCategoryPage }} / {{ kelolaCategoryPageCount }}</span>
                <button
                  class="btn btn-ghost btn-sm"
                  type="button"
                  :disabled="kelolaCategoryPage >= kelolaCategoryPageCount"
                  @click="kelolaCategoryPage++"
                >Berikutnya</button>
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

        <!-- ALUR KAS BULANAN (snapshot editable) — Owner + Admin bengkel -->
        <section v-if="page==='cashflow' && canAccessCashflow">
          <div class="page-head">
            <div>
              <h2 class="brand">Alur Kas (Input)</h2>
              <p>Pemasukan &amp; pengeluaran per pos (snapshot bulanan) — dari transaksi + bagian toko upah, bisa diedit</p>
            </div>
          </div>

          <div class="card">
            <div class="filter-bar cashflow-filters">
              <div class="field">
                <label>Bulan</label>
                <select v-model.number="cashflowFilter.month" @change="onCashflowFilterChange">
                  <option v-for="m in 12" :key="'cfm'+m" :value="m">{{ cashflowMonthNames[m] }}</option>
                </select>
              </div>
              <div class="field">
                <label>Tahun</label>
                <select v-model.number="cashflowFilter.year" @change="onCashflowFilterChange">
                  <option v-for="y in cashflowYears" :key="'cfy'+y" :value="y">{{ y }}</option>
                </select>
              </div>
              <div v-if="isOwner" class="field">
                <label>Cabang</label>
                <select v-model="cashflowFilter.branch_id" @change="onCashflowFilterChange">
                  <option value="">Semua cabang</option>
                  <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                </select>
              </div>
              <div class="field field-actions">
                <label>&nbsp;</label>
                <div class="att-actions" style="margin:0">
                  <button class="btn btn-ghost btn-sm" type="button" :disabled="loading" @click="loadCashflowBoard">Muat Ulang</button>
                  <button class="btn btn-ghost btn-sm" type="button" :disabled="loading" @click="exportCashflowPdf('attachment')">Export PDF</button>
                  <button class="btn btn-ghost btn-sm" type="button" :disabled="loading" @click="exportCashflowPdf('inline')">Buka PDF</button>
                </div>
              </div>
            </div>

            <div class="cashflow-toolbar">
              <div class="tab-row" style="margin:0">
                <button type="button" class="tab-btn" :class="{active: cashflowFilter.view==='detail'}" @click="cashflowFilter.view='detail'">Detail</button>
                <button type="button" class="tab-btn" :class="{active: cashflowFilter.view==='ringkas'}" @click="cashflowFilter.view='ringkas'">Ringkas</button>
              </div>
              <div class="filter-meta" style="margin:0">
                {{ cashflowMonthLabel }}
                · Pemasukan <strong class="value-income">{{ formatRp(cashflowBoard.meta?.totals?.total_income || 0) }}</strong>
                · Pengeluaran <strong class="value-expense">{{ formatRp(cashflowBoard.meta?.totals?.total_expense || 0) }}</strong>
                · Laba/Rugi
                <strong :class="amountClass(cashflowBoard.meta?.totals?.net_profit)">
                  {{ formatRp(cashflowBoard.meta?.totals?.net_profit || 0) }}
                </strong>
              </div>
            </div>

            <div class="table-wrap" style="margin-top:12px;margin-bottom:16px">
              <table>
                <thead>
                  <tr>
                    <th class="col-no">No</th>
                    <th>Cabang</th>
                    <th>Pemasukan</th>
                    <th>Pengeluaran</th>
                    <th>Laba/Rugi</th>
                    <th>Status</th>
                    <th>Aksi</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(row, idx) in (cashflowBoard.rows || [])" :key="'cf'+row.branch_id">
                    <td class="col-no">{{ rowNo(idx) }}</td>
                    <td>
                      <button
                        v-if="cashflowFilter.view==='ringkas'"
                        type="button"
                        class="cashflow-expand-btn"
                        @click="toggleCashflowBranch(row.branch_id)"
                      >{{ isCashflowBranchExpanded(row.branch_id) ? '-' : '+' }}</button>
                      <strong>{{ row.branch_name }}</strong>
                    </td>
                    <td class="value-income">{{ formatRp(row.total_income) }}</td>
                    <td class="value-expense">{{ formatRp(row.total_expense) }}</td>
                    <td>
                      <strong :class="amountClass(row.net_profit)">{{ formatRp(row.net_profit) }}</strong>
                    </td>
                    <td>
                      <span class="badge" :class="row.exists ? 'badge-approved' : 'badge-pending'">
                        {{ row.exists ? 'Tersimpan' : 'Usulan sistem' }}
                      </span>
                    </td>
                    <td>
                      <button class="btn btn-ghost btn-sm" type="button" @click="openCfEditor(row)">Kelola</button>
                    </td>
                  </tr>
                  <tr v-if="(cashflowBoard.rows || []).length" class="closing-subtotal-row">
                    <td colspan="2"><strong>Total</strong></td>
                    <td class="value-income"><strong>{{ formatRp(cashflowBoard.meta?.totals?.total_income || 0) }}</strong></td>
                    <td class="value-expense"><strong>{{ formatRp(cashflowBoard.meta?.totals?.total_expense || 0) }}</strong></td>
                    <td>
                      <strong :class="amountClass(cashflowBoard.meta?.totals?.net_profit)">
                        {{ formatRp(cashflowBoard.meta?.totals?.net_profit || 0) }}
                      </strong>
                    </td>
                    <td colspan="2"></td>
                  </tr>
                  <tr v-if="!(cashflowBoard.rows || []).length">
                    <td colspan="7">Tidak ada cabang.</td>
                  </tr>
                </tbody>
              </table>
            </div>

            <template v-if="cashflowFilter.view==='detail' || Object.keys(cashflowExpanded).some(k => cashflowExpanded[k])">
              <template v-for="row in (cashflowBoard.rows || [])" :key="'cfd'+row.branch_id">
                <div v-if="isCashflowBranchExpanded(row.branch_id)" style="margin-bottom:16px">
                  <div class="panel-title" style="margin-bottom:8px">{{ row.branch_name }} — rincian pos</div>
                  <div class="table-wrap">
                    <table>
                      <thead>
                        <tr><th>Jenis</th><th>Pos</th><th>Nominal</th></tr>
                      </thead>
                      <tbody>
                        <tr class="cashflow-section-row is-income">
                          <td colspan="3"><strong>Pemasukan</strong></td>
                        </tr>
                        <tr v-for="(line, li) in (row.lines || []).filter(l => l.type==='income')" :key="'cfi'+row.branch_id+li">
                          <td class="muted">Pemasukan</td>
                          <td>{{ line.name }}</td>
                          <td class="value-income">{{ formatRp(line.amount) }}</td>
                        </tr>
                        <tr v-if="!(row.lines || []).some(l => l.type==='income')">
                          <td colspan="3" class="muted">Belum ada pos pemasukan.</td>
                        </tr>
                        <tr class="cashflow-total-row is-income">
                          <td colspan="2"><strong>Total Pemasukan</strong></td>
                          <td class="value-income"><strong>{{ formatRp(row.total_income) }}</strong></td>
                        </tr>

                        <tr class="cashflow-section-row is-expense">
                          <td colspan="3"><strong>Pengeluaran</strong></td>
                        </tr>
                        <tr v-for="(line, li) in (row.lines || []).filter(l => l.type==='expense')" :key="'cfe'+row.branch_id+li">
                          <td class="muted">Pengeluaran</td>
                          <td>{{ line.name }}</td>
                          <td class="value-expense">{{ formatRp(line.amount) }}</td>
                        </tr>
                        <tr v-if="!(row.lines || []).some(l => l.type==='expense')">
                          <td colspan="3" class="muted">Belum ada pos pengeluaran.</td>
                        </tr>
                        <tr class="cashflow-total-row is-expense">
                          <td colspan="2"><strong>Total Pengeluaran</strong></td>
                          <td class="value-expense"><strong>{{ formatRp(row.total_expense) }}</strong></td>
                        </tr>

                        <tr class="cashflow-total-row is-laba">
                          <td colspan="2"><strong>Laba/Rugi</strong></td>
                          <td><strong :class="amountClass(row.net_profit)">{{ formatRp(row.net_profit) }}</strong></td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                </div>
              </template>
            </template>

            <div v-if="cfEditor.open" class="ps-editor" style="margin-top:8px">
              <div class="panel-title" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
                <span>Kelola — {{ cfEditor.branch_name }} · {{ cashflowMonthLabel }}</span>
                <button class="btn btn-ghost btn-sm" type="button" @click="closeCfEditor">Tutup</button>
              </div>

              <div class="att-actions" style="margin:10px 0 14px;flex-wrap:wrap">
                <button class="btn btn-ghost btn-sm" type="button" :disabled="loading" @click="seedCashflowFromSystem">Isi dari Sistem</button>
                <button class="btn btn-ghost btn-sm" type="button" :disabled="loading" @click="copyCashflowPrevious">Salin Pos Bulan Lalu</button>
                <button class="btn btn-primary btn-sm" type="button" :disabled="loading" @click="saveCashflowWorkbook">Simpan Snapshot</button>
              </div>

              <div class="report-kpi-row" style="margin-bottom:14px">
                <div class="report-kpi">
                  <span class="report-kpi-label">Pemasukan</span>
                  <strong class="value-income">{{ formatRp(cfEditor.total_income) }}</strong>
                </div>
                <div class="report-kpi">
                  <span class="report-kpi-label">Pengeluaran</span>
                  <strong class="value-expense">{{ formatRp(cfEditor.total_expense) }}</strong>
                </div>
                <div class="report-kpi">
                  <span class="report-kpi-label">Laba/Rugi</span>
                  <strong :class="amountClass(cfEditor.net_profit)">{{ formatRp(cfEditor.net_profit) }}</strong>
                </div>
              </div>

              <div class="grid-2">
                <div>
                  <div class="panel-title" style="margin-bottom:8px;display:flex;justify-content:space-between;align-items:center">
                    <span>Pemasukan</span>
                    <button class="btn btn-ghost btn-sm" type="button" :disabled="loading" @click="addCfLine('income')">+ Pos</button>
                  </div>
                  <div class="table-wrap">
                    <table>
                      <thead><tr><th>Pos</th><th>Nominal</th><th></th></tr></thead>
                      <tbody>
                        <tr v-for="line in cfIncomeLines" :key="line.key">
                          <td><input type="text" v-model="line.name" :disabled="loading" placeholder="Nama pos" /></td>
                          <td>
                            <input
                              type="text"
                              inputmode="numeric"
                              :disabled="loading"
                              :value="formatInputNumber(line.amount)"
                              @focus="onPayrollFocus"
                              @input="onCfLineAmountInput(line, $event)"
                            />
                          </td>
                          <td>
                            <button class="btn btn-ghost btn-sm" type="button" :disabled="loading" @click="removeCfLine(line)">Hapus</button>
                          </td>
                        </tr>
                        <tr v-if="!cfIncomeLines.length"><td colspan="3">Belum ada pos pemasukan.</td></tr>
                      </tbody>
                    </table>
                  </div>
                </div>
                <div>
                  <div class="panel-title" style="margin-bottom:8px;display:flex;justify-content:space-between;align-items:center">
                    <span>Pengeluaran</span>
                    <button class="btn btn-ghost btn-sm" type="button" :disabled="loading" @click="addCfLine('expense')">+ Pos</button>
                  </div>
                  <div class="table-wrap">
                    <table>
                      <thead><tr><th>Pos</th><th>Nominal</th><th></th></tr></thead>
                      <tbody>
                        <tr v-for="line in cfExpenseLines" :key="line.key">
                          <td><input type="text" v-model="line.name" :disabled="loading" placeholder="Nama pos" /></td>
                          <td>
                            <input
                              type="text"
                              inputmode="numeric"
                              :disabled="loading"
                              :value="formatInputNumber(line.amount)"
                              @focus="onPayrollFocus"
                              @input="onCfLineAmountInput(line, $event)"
                            />
                          </td>
                          <td>
                            <button class="btn btn-ghost btn-sm" type="button" :disabled="loading" @click="removeCfLine(line)">Hapus</button>
                          </td>
                        </tr>
                        <tr v-if="!cfExpenseLines.length"><td colspan="3">Belum ada pos pengeluaran.</td></tr>
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>
              <p class="filter-meta" style="margin-top:10px">
                Edit hanya mengubah snapshot Alur Kas. Transaksi asli tetap utuh.
                Bengkel: pos “Bagian Toko Upah” dihitung dari job × (100 − % teknisi).
                Salin bulan lalu = nama pos saja. Pakai “Isi dari Sistem” untuk nominal dari transaksi bulan ini.
              </p>
            </div>
          </div>
        </section>

        <!-- REPORTS — Owner semua; Admin sesuai tipe cabang -->
        <section v-if="page==='reports' && canAccessReports">
          <div class="page-head">
            <div>
              <h2 class="brand">Laporan</h2>
              <p v-if="isOwner">Keuangan, konter, bengkel, absensi — lihat atau unduh PDF</p>
              <p v-else-if="isWorkshopBranch">Alur Kas, Brilink, dan Upah Bengkel cabang Anda</p>
              <p v-else>Laporan operasional &amp; keuangan cabang Anda — lihat atau unduh PDF</p>
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
                <select
                  v-model="reportForm.branch_id"
                  @change="onReportUpahBranchChange"
                >
                  <option value="">{{ reportForm.type === 'upah' ? 'Semua cabang bengkel' : (reportForm.type === 'gaji' || reportForm.type === 'closing' || reportForm.type === 'servis' ? 'Semua cabang konter' : 'Semua cabang') }}</option>
                  <option v-for="b in reportBranches" :key="b.id" :value="b.id">{{ b.name }}</option>
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
              <div v-if="reportForm.type==='upah'" class="field">
                <label>Teknisi</label>
                <select v-model="reportForm.employee_id">
                  <option value="">Semua teknisi</option>
                  <option v-for="e in reportUpahTechnicians" :key="e.id" :value="e.id">
                    {{ reportTechLabel(e) }}
                  </option>
                </select>
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
                  <thead>
                    <tr>
                      <th class="col-no">No</th>
                      <th>Tanggal</th>
                      <th>Pemasukan</th>
                      <th>Pengeluaran</th>
                      <th>Selisih</th>
                      <th class="col-aksi">Aksi</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="(row, idx) in (reportResult.data?.harian || [])" :key="row.tanggal">
                      <td class="col-no">{{ rowNo(idx) }}</td>
                      <td>{{ formatDate(row.tanggal) || String(row.tanggal || '').slice(0, 10) }}</td>
                      <td class="value-income">{{ formatRp(row.pemasukan) }}</td>
                      <td class="value-expense">{{ formatRp(row.pengeluaran) }}</td>
                      <td>{{ formatRp(row.selisih) }}</td>
                      <td class="col-aksi">
                        <button class="btn btn-ghost btn-sm" type="button" @click="openReportRingkasanDetail(row)">Detail</button>
                      </td>
                    </tr>
                    <tr v-if="!(reportResult.data?.harian || []).length"><td colspan="6">Tidak ada data.</td></tr>
                  </tbody>
                </table>
              </div>
            </template>

            <template v-else-if="reportForm.type==='kategori'">
              <div class="filter-meta">
                {{ reportResult.data?.jumlah || 0 }} transaksi ·
                Pemasukan {{ formatRp(reportResult.data?.total_pemasukan) }} ·
                Pengeluaran {{ formatRp(reportResult.data?.total_pengeluaran) }}
              </div>
              <div v-for="g in (reportResult.data?.groups || [])" :key="g.category_id" style="margin-bottom:16px">
                <div class="panel-title" style="margin-bottom:8px">
                  {{ g.nama }}
                  <span class="muted"> · {{ g.tipe === 'income' ? 'Pemasukan' : 'Pengeluaran' }} · {{ g.jumlah }} trx</span>
                </div>
                <div class="table-wrap">
                  <table>
                    <thead>
                      <tr>
                        <th class="col-no">No</th>
                        <th>Tanggal</th>
                        <th>Cabang</th>
                        <th>Akun</th>
                        <th>Nominal</th>
                        <th>Keterangan</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-for="(row, idx) in (g.rows || [])" :key="row.id">
                        <td class="col-no">{{ idx + 1 }}</td>
                        <td>{{ row.tanggal }}</td>
                        <td>{{ row.cabang }}</td>
                        <td>{{ row.akun || '—' }}</td>
                        <td :class="g.tipe==='income' ? 'value-income' : 'value-expense'">{{ formatRp(row.nominal) }}</td>
                        <td class="desc-cell">{{ row.keterangan || '—' }}</td>
                      </tr>
                      <tr>
                        <td></td>
                        <td colspan="3"><strong>Total {{ g.nama }}</strong></td>
                        <td :class="g.tipe==='income' ? 'value-income' : 'value-expense'"><strong>{{ formatRp(g.total) }}</strong></td>
                        <td></td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </div>
              <div v-if="!(reportResult.data?.groups || []).length" class="empty-hint">Tidak ada data.</div>
              <p v-else class="closing-hint" style="margin-top:4px">
                Total pemasukan <strong class="value-income">{{ formatRp(reportResult.data?.total_pemasukan) }}</strong>
                · Total pengeluaran <strong class="value-expense">{{ formatRp(reportResult.data?.total_pengeluaran) }}</strong>
              </p>
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

            <template v-else-if="reportForm.type==='brilink'">
              <div class="filter-meta">
                {{ reportResult.data?.jumlah || 0 }} hari ·
                Total {{ formatRp(reportResult.data?.total_hari_ini) }} ·
                Keuntungan {{ formatRp(reportResult.data?.total_keuntungan) }}
              </div>
              <div class="table-wrap">
                <table>
                  <thead>
                    <tr>
                      <th class="col-no">No</th>
                      <th>Tanggal</th>
                      <th v-if="isOwner">Cabang</th>
                      <th>Saldo Kemarin</th>
                      <th>Total Hari Ini</th>
                      <th>Keuntungan</th>
                      <th>Oleh</th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody>
                    <template v-for="(row, idx) in (reportResult.data?.rows || [])" :key="'rbr-'+row.id">
                      <tr>
                        <td class="col-no">{{ rowNo(idx) }}</td>
                        <td>{{ row.tanggal }}</td>
                        <td v-if="isOwner">{{ row.cabang }}</td>
                        <td>{{ formatRp(row.saldo_kemarin) }}</td>
                        <td class="value-income">{{ formatRp(row.total) }}</td>
                        <td style="font-weight:600;color:#0F766E">{{ formatRp(row.keuntungan) }}</td>
                        <td>{{ row.oleh || '—' }}</td>
                        <td>
                          <button class="btn btn-ghost btn-sm" type="button" @click="row._open = !row._open">{{ row._open ? 'Tutup' : 'Detail' }}</button>
                        </td>
                      </tr>
                      <tr v-if="row._open">
                        <td :colspan="isOwner ? 8 : 7" style="background:#f8fafc">
                          <div class="table-wrap">
                            <table>
                              <thead><tr><th>Item</th><th>Nominal</th></tr></thead>
                              <tbody>
                                <tr v-for="(l, li) in (row.lines || [])" :key="row.id+'-l-'+li">
                                  <td>{{ l.nama }}</td>
                                  <td>{{ formatRp(l.nominal) }}</td>
                                </tr>
                                <tr v-if="!(row.lines || []).length"><td colspan="2">Tidak ada rincian.</td></tr>
                              </tbody>
                            </table>
                          </div>
                        </td>
                      </tr>
                    </template>
                    <tr v-if="!(reportResult.data?.rows || []).length">
                      <td :colspan="isOwner ? 8 : 7">Tidak ada data.</td>
                    </tr>
                  </tbody>
                  <tfoot v-if="(reportResult.data?.rows || []).length">
                    <tr style="font-weight:700;background:#f1f5f9">
                      <td class="col-no"></td>
                      <td :colspan="isOwner ? 2 : 1">Total</td>
                      <td>{{ formatRp(reportResult.data?.total_saldo_kemarin) }}</td>
                      <td class="value-income">{{ formatRp(reportResult.data?.total_hari_ini) }}</td>
                      <td style="color:#0F766E">{{ formatRp(reportResult.data?.total_keuntungan) }}</td>
                      <td></td>
                      <td></td>
                    </tr>
                  </tfoot>
                </table>
              </div>
            </template>

            <template v-else-if="reportForm.type==='keuntungan-pulsa'">
              <div class="filter-meta">
                {{ reportResult.data?.jumlah || 0 }} hari ·
                Saldo terpotong {{ formatRp(reportResult.data?.total_saldo_terpotong) }} ·
                Total uang {{ formatRp(reportResult.data?.total_uang) }} ·
                Keuntungan {{ formatRp(reportResult.data?.total_keuntungan) }}
              </div>
              <div class="table-wrap">
                <table>
                  <thead>
                    <tr>
                      <th class="col-no">No</th>
                      <th>Tanggal</th>
                      <th v-if="isOwner">Cabang</th>
                      <th>Uang Pulsa</th>
                      <th>Pengeluaran</th>
                      <th>Total Uang</th>
                      <th>Saldo Terpotong</th>
                      <th>Keuntungan</th>
                      <th>Oleh</th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody>
                    <template v-for="(row, idx) in (reportResult.data?.rows || [])" :key="row.id">
                      <tr>
                        <td class="col-no">{{ rowNo(idx) }}</td>
                        <td>{{ row.tanggal }}</td>
                        <td v-if="isOwner">{{ row.cabang }}</td>
                        <td>{{ formatRp(row.uang_pulsa) }}</td>
                        <td>{{ formatRp(row.pengeluaran) }}</td>
                        <td class="value-income">{{ formatRp(row.total_uang) }}</td>
                        <td class="value-expense">{{ formatRp(row.saldo_terpotong) }}</td>
                        <td style="font-weight:600;color:#0F766E">{{ formatRp(row.keuntungan) }}</td>
                        <td>{{ row.oleh || '—' }}</td>
                        <td>
                          <button
                            class="btn btn-ghost btn-sm"
                            type="button"
                            @click="row._open = !row._open"
                          >{{ row._open ? 'Tutup' : 'Detail' }}</button>
                        </td>
                      </tr>
                      <tr v-if="row._open">
                        <td :colspan="isOwner ? 10 : 9" style="background:#f8fafc">
                          <div class="panel-title" style="margin:0 0 6px">Provider</div>
                          <div class="table-wrap" style="margin-bottom:10px">
                            <table>
                              <thead>
                                <tr><th>Provider</th><th>Kemarin</th><th>Tambah</th><th>Sekarang</th><th>Terpakai</th></tr>
                              </thead>
                              <tbody>
                                <tr v-for="(b, bi) in (row.balances || [])" :key="row.id+'-b-'+bi">
                                  <td>{{ b.provider }}</td>
                                  <td>{{ formatRp(b.kemarin) }}</td>
                                  <td>{{ formatRp(b.tambah) }}</td>
                                  <td>{{ formatRp(b.sekarang) }}</td>
                                  <td>{{ formatRp(b.terpakai) }}</td>
                                </tr>
                                <tr v-if="!(row.balances || []).length"><td colspan="5">Tidak ada rincian provider.</td></tr>
                              </tbody>
                            </table>
                          </div>
                          <div class="panel-title" style="margin:0 0 6px">Pengeluaran</div>
                          <div class="table-wrap">
                            <table>
                              <thead><tr><th>Keterangan</th><th>Nominal</th></tr></thead>
                              <tbody>
                                <tr v-for="(e, ei) in (row.expenses || [])" :key="row.id+'-e-'+ei">
                                  <td>{{ e.nama }}</td>
                                  <td>{{ formatRp(e.nominal) }}</td>
                                </tr>
                                <tr v-if="!(row.expenses || []).length"><td colspan="2">Tidak ada pengeluaran.</td></tr>
                              </tbody>
                            </table>
                          </div>
                        </td>
                      </tr>
                    </template>
                    <tr v-if="!(reportResult.data?.rows || []).length">
                      <td :colspan="isOwner ? 10 : 9">Tidak ada data.</td>
                    </tr>
                  </tbody>
                  <tfoot v-if="(reportResult.data?.rows || []).length">
                    <tr style="font-weight:700;background:#f1f5f9">
                      <td class="col-no"></td>
                      <td :colspan="isOwner ? 2 : 1">Total</td>
                      <td>{{ formatRp(reportResult.data?.total_uang_pulsa) }}</td>
                      <td>{{ formatRp(reportResult.data?.total_pengeluaran) }}</td>
                      <td class="value-income">{{ formatRp(reportResult.data?.total_uang) }}</td>
                      <td class="value-expense">{{ formatRp(reportResult.data?.total_saldo_terpotong) }}</td>
                      <td style="color:#0F766E">{{ formatRp(reportResult.data?.total_keuntungan) }}</td>
                      <td></td>
                      <td></td>
                    </tr>
                  </tfoot>
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
                <br>
                <span class="muted">Total = Gapok + PIC + HP + Service + ACC + Bonus − Hutang − Kasbon (kasbon dari transaksi).</span>
              </div>
              <div class="table-wrap">
                <table>
                  <thead>
                    <tr>
                      <th class="col-no">No</th><th>Cabang</th><th>Karyawan</th><th>Status</th>
                      <th>Hadir</th><th>Gapok</th><th>PIC</th><th>HP</th><th>Service</th>
                      <th>ACC</th><th>Bonus</th><th>Hutang</th><th>Kasbon</th><th>Total</th>
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
                      <td>{{ formatRp(row.insentif_pic) }}</td>
                      <td>{{ formatRp(row.insentif_hp) }}</td>
                      <td>{{ formatRp(row.insentif_service) }}</td>
                      <td>{{ formatRp(row.acc) }}</td>
                      <td>{{ formatRp(row.bonus) }}</td>
                      <td class="value-expense">{{ formatRp(row.hutang) }}</td>
                      <td class="value-expense">{{ formatRp(row.pengeluaran) }}</td>
                      <td style="font-weight:600" :class="Number(row.total||0) < 0 ? 'value-expense' : ''">{{ formatRp(row.total) }}</td>
                    </tr>
                    <tr v-if="(reportResult.data?.rows || []).length" class="closing-subtotal-row">
                      <td class="col-no"></td>
                      <td colspan="2"><strong>Subtotal</strong></td>
                      <td></td>
                      <td><strong>{{ reportResult.data?.total_hadir ?? 0 }}</strong></td>
                      <td><strong>{{ formatRp(reportResult.data?.total_gapok) }}</strong></td>
                      <td><strong>{{ formatRp(reportResult.data?.total_insentif_pic) }}</strong></td>
                      <td><strong>{{ formatRp(reportResult.data?.total_insentif_hp) }}</strong></td>
                      <td><strong>{{ formatRp(reportResult.data?.total_insentif_service) }}</strong></td>
                      <td><strong>{{ formatRp(reportResult.data?.total_acc) }}</strong></td>
                      <td><strong>{{ formatRp(reportResult.data?.total_bonus) }}</strong></td>
                      <td class="value-expense"><strong>{{ formatRp(reportResult.data?.total_hutang) }}</strong></td>
                      <td class="value-expense"><strong>{{ formatRp(reportResult.data?.total_pengeluaran) }}</strong></td>
                      <td style="font-weight:700" :class="Number(reportResult.data?.total_gaji||0) < 0 ? 'value-expense' : ''">
                        <strong>{{ formatRp(reportResult.data?.total_gaji) }}</strong>
                      </td>
                    </tr>
                    <tr v-if="!(reportResult.data?.rows || []).length"><td colspan="14">Tidak ada data gaji.</td></tr>
                  </tbody>
                </table>
              </div>
            </template>

            <template v-else-if="reportForm.type==='upah'">
              <div class="filter-meta" style="margin-bottom:10px">
                {{ reportResult.meta?.teknisi || 'Semua teknisi' }} ·
                {{ reportResult.data?.jumlah || 0 }} job ·
                {{ reportResult.data?.jumlah_teknisi || 0 }} teknisi ·
                Gross {{ formatRp(reportResult.data?.total_gross) }} ·
                Upah {{ formatRp(reportResult.data?.total_net) }} ·
                Toko {{ formatRp(reportResult.data?.total_shop) }}
              </div>
              <div class="report-kpi-row" style="margin-bottom:14px">
                <div class="report-kpi">
                  <span class="report-kpi-label">Job</span>
                  <strong>{{ reportResult.data?.jumlah || 0 }}</strong>
                </div>
                <div class="report-kpi">
                  <span class="report-kpi-label">Gross</span>
                  <strong class="value-income">{{ formatRp(reportResult.data?.total_gross) }}</strong>
                </div>
                <div class="report-kpi">
                  <span class="report-kpi-label">Upah teknisi</span>
                  <strong>{{ formatRp(reportResult.data?.total_net) }}</strong>
                </div>
                <div class="report-kpi">
                  <span class="report-kpi-label">Bagian toko</span>
                  <strong>{{ formatRp(reportResult.data?.total_shop) }}</strong>
                </div>
              </div>

              <div class="panel-title" style="margin-bottom:8px">Ringkasan per Teknisi</div>
              <div class="table-wrap" style="margin-bottom:16px">
                <table>
                  <thead>
                    <tr>
                      <th class="col-no">No</th>
                      <th>Teknisi</th>
                      <th>Cabang</th>
                      <th>Job</th>
                      <th>Gross</th>
                      <th>% Upah</th>
                      <th>Upah</th>
                      <th>% Toko</th>
                      <th>Toko</th>
                      <th class="col-aksi">Aksi</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="(row, idx) in (reportResult.data?.by_teknisi || [])" :key="'t'+row.employee_id">
                      <td class="col-no">{{ rowNo(idx) }}</td>
                      <td><strong>{{ row.teknisi }}</strong></td>
                      <td>{{ row.cabang }}</td>
                      <td>{{ row.jumlah_job }}</td>
                      <td class="value-income">{{ formatRp(row.total_gross) }}</td>
                      <td style="font-weight:600">{{ formatPctShare(row.tech_share_pct) }}{{ row.pct_mixed ? '*' : '' }}</td>
                      <td style="font-weight:600">{{ formatRp(row.total_net) }}</td>
                      <td>{{ formatPctShare(row.shop_share_pct) }}{{ row.pct_mixed ? '*' : '' }}</td>
                      <td>{{ formatRp(row.total_shop) }}</td>
                      <td class="col-aksi">
                        <button class="btn btn-ghost btn-sm" type="button" @click="openReportUpahTechDetail(row)">Detail</button>
                      </td>
                    </tr>
                    <tr v-if="(reportResult.data?.by_teknisi || []).length" class="closing-subtotal-row">
                      <td colspan="3"><strong>Total</strong></td>
                      <td><strong>{{ reportResult.data?.jumlah || 0 }}</strong></td>
                      <td class="value-income"><strong>{{ formatRp(reportResult.data?.total_gross) }}</strong></td>
                      <td></td>
                      <td><strong>{{ formatRp(reportResult.data?.total_net) }}</strong></td>
                      <td></td>
                      <td><strong>{{ formatRp(reportResult.data?.total_shop) }}</strong></td>
                      <td></td>
                    </tr>
                    <tr v-if="!(reportResult.data?.by_teknisi || []).length">
                      <td colspan="10">Tidak ada ringkasan teknisi.</td>
                    </tr>
                  </tbody>
                </table>
                <p v-if="(reportResult.data?.by_teknisi || []).some(r => r.pct_mixed)" class="filter-meta" style="margin-top:8px">
                  * Persentase efektif (ada lebih dari satu % bagi di periode laporan).
                </p>
              </div>
            </template>

            <template v-else-if="reportForm.type==='bagi-hasil'">
              <div class="filter-meta" style="margin-bottom:10px">
                {{ reportResult.data?.periode_label }} ·
                {{ reportResult.data?.jumlah || 0 }} cabang ·
                Draft {{ reportResult.data?.draft || 0 }} ·
                Terkunci {{ reportResult.data?.locked || 0 }}
              </div>
              <div class="report-kpi-row" style="margin-bottom:14px">
                <div class="report-kpi">
                  <span class="report-kpi-label">Pemasukan</span>
                  <strong class="value-income">{{ formatRp(reportResult.data?.total_income) }}</strong>
                </div>
                <div class="report-kpi">
                  <span class="report-kpi-label">Pengeluaran</span>
                  <strong class="value-expense">{{ formatRp(reportResult.data?.total_expense) }}</strong>
                </div>
                <div class="report-kpi">
                  <span class="report-kpi-label">Laba bersih</span>
                  <strong :class="Number(reportResult.data?.total_net_profit || 0) < 0 ? 'value-expense' : ''">{{ formatRp(reportResult.data?.total_net_profit) }}</strong>
                </div>
                <div class="report-kpi">
                  <span class="report-kpi-label">Bagian PIC</span>
                  <strong>{{ formatRp(reportResult.data?.total_pic_amount) }}</strong>
                </div>
              </div>

              <div class="panel-title" style="margin-bottom:8px">Ringkasan per Cabang</div>
              <div class="table-wrap" style="margin-bottom:16px">
                <table>
                  <thead>
                    <tr>
                      <th class="col-no">No</th>
                      <th>Periode</th>
                      <th>Cabang</th>
                      <th>PIC</th>
                      <th>%</th>
                      <th>Pemasukan</th>
                      <th>Pengeluaran</th>
                      <th>Laba Bersih</th>
                      <th>Bagian PIC</th>
                      <th>Status</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="(row, idx) in (reportResult.data?.rows || [])" :key="'bh'+row.id">
                      <td class="col-no">{{ rowNo(idx) }}</td>
                      <td>{{ row.periode_label }}</td>
                      <td><strong>{{ row.cabang }}</strong></td>
                      <td>{{ row.pic_name || '—' }}</td>
                      <td>{{ formatPctShare(row.pic_share_pct) }}</td>
                      <td class="value-income">{{ formatRp(row.total_income) }}</td>
                      <td class="value-expense">{{ formatRp(row.total_expense) }}</td>
                      <td :class="Number(row.net_profit) < 0 ? 'value-expense' : ''">{{ formatRp(row.net_profit) }}</td>
                      <td style="font-weight:600">{{ formatRp(row.pic_amount) }}</td>
                      <td>
                        <span class="badge" :class="row.status==='locked' ? 'badge-rejected' : 'badge-pending'">
                          {{ row.status==='locked' ? 'Terkunci' : 'Draft' }}
                        </span>
                      </td>
                    </tr>
                    <tr v-if="(reportResult.data?.rows || []).length" class="closing-subtotal-row">
                      <td colspan="5"><strong>Total</strong></td>
                      <td class="value-income"><strong>{{ formatRp(reportResult.data?.total_income) }}</strong></td>
                      <td class="value-expense"><strong>{{ formatRp(reportResult.data?.total_expense) }}</strong></td>
                      <td :class="Number(reportResult.data?.total_net_profit || 0) < 0 ? 'value-expense' : ''"><strong>{{ formatRp(reportResult.data?.total_net_profit) }}</strong></td>
                      <td><strong>{{ formatRp(reportResult.data?.total_pic_amount) }}</strong></td>
                      <td></td>
                    </tr>
                    <tr v-if="!(reportResult.data?.rows || []).length">
                      <td colspan="10">Tidak ada data bagi hasil. Isi dulu di menu Sistem → Bagi Hasil.</td>
                    </tr>
                  </tbody>
                </table>
              </div>

              <template v-for="row in (reportResult.data?.rows || [])" :key="'bhd'+row.id">
                <div class="panel-title" style="margin-bottom:8px">
                  Rincian Pos — {{ row.cabang }} ({{ row.periode_label }})
                </div>
                <div class="table-wrap" style="margin-bottom:16px">
                  <table>
                    <thead>
                      <tr>
                        <th>Jenis</th>
                        <th>Pos</th>
                        <th>Nominal</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-for="(line, li) in (row.income_lines || [])" :key="'i'+row.id+'-'+li">
                        <td>Pemasukan</td>
                        <td>{{ line.name }}</td>
                        <td class="value-income">{{ formatRp(line.amount) }}</td>
                      </tr>
                      <tr v-for="(line, li) in (row.expense_lines || [])" :key="'e'+row.id+'-'+li">
                        <td>Pengeluaran</td>
                        <td>{{ line.name }}</td>
                        <td class="value-expense">{{ formatRp(line.amount) }}</td>
                      </tr>
                      <tr v-if="!(row.income_lines || []).length && !(row.expense_lines || []).length">
                        <td colspan="3">Tidak ada rincian pos.</td>
                      </tr>
                      <tr class="closing-subtotal-row">
                        <td colspan="2"><strong>Bagian PIC ({{ formatPctShare(row.pic_share_pct) }})</strong></td>
                        <td><strong>{{ formatRp(row.pic_amount) }}</strong></td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </template>
            </template>

            <template v-else-if="reportForm.type==='closing'">
              <div class="filter-meta">
                {{ reportResult.data?.periode_label }} ·
                Closing {{ reportResult.data?.total_qty || 0 }} /
                Target {{ reportResult.data?.total_target || 0 }}
                <span v-if="reportResult.data?.pct != null"> ({{ reportResult.data.pct }}%)</span>
                · Kirim WA per karyawan (nomor dari Data Karyawan)
              </div>
              <div class="report-kpi-row">
                <div class="report-kpi">
                  <span class="report-kpi-label">Karyawan</span>
                  <strong>{{ reportResult.data?.jumlah_karyawan || 0 }}</strong>
                </div>
                <div class="report-kpi report-kpi-ok">
                  <span class="report-kpi-label">Tercapai</span>
                  <strong>{{ reportResult.data?.jumlah_tercapai || 0 }}</strong>
                </div>
                <div class="report-kpi report-kpi-bad">
                  <span class="report-kpi-label">Belum tercapai</span>
                  <strong>{{ reportResult.data?.jumlah_belum || 0 }}</strong>
                </div>
                <div class="report-kpi">
                  <span class="report-kpi-label">Capaian agregat</span>
                  <strong>{{ reportResult.data?.pct != null ? (reportResult.data.pct + '%') : '—' }}</strong>
                </div>
              </div>
              <div class="table-wrap">
                <table class="report-closing-table">
                  <thead>
                    <tr>
                      <th class="col-no">No</th>
                      <th>Karyawan</th>
                      <th>Cabang</th>
                      <th>Closing</th>
                      <th>Target</th>
                      <th>Capaian</th>
                      <th>Status</th>
                      <th>Selisih</th>
                      <th>Aksi</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="(row, idx) in (reportResult.data?.rows || [])" :key="row.employee_id">
                      <td class="col-no">{{ rowNo(idx) }}</td>
                      <td>
                        <strong>{{ row.nama }}</strong>
                        <div class="muted" style="font-size:.78rem">{{ row.phone || 'HP belum diisi' }}</div>
                      </td>
                      <td>{{ row.cabang }}</td>
                      <td>{{ row.qty }}</td>
                      <td>{{ row.target }}</td>
                      <td class="report-progress-cell">
                        <div class="report-progress">
                          <div class="report-progress-track">
                            <div
                              class="report-progress-bar"
                              :class="row.tercapai ? 'is-ok' : 'is-bad'"
                              :style="{ width: Math.min(100, Number(row.pct || 0)) + '%' }"
                            ></div>
                          </div>
                          <span :class="row.tercapai ? 'value-income' : 'value-expense'">
                            {{ row.pct != null ? (row.pct + '%') : '—' }}
                          </span>
                        </div>
                      </td>
                      <td>
                        <span class="badge" :class="row.tercapai ? 'badge-approved' : 'badge-rejected'">
                          {{ row.status_label || (row.tercapai ? 'Tercapai' : 'Belum tercapai') }}
                        </span>
                      </td>
                      <td :class="row.selisih >= 0 ? 'value-income' : 'value-expense'">
                        {{ row.selisih > 0 ? '+' : '' }}{{ row.selisih }}
                      </td>
                      <td>
                        <button
                          type="button"
                          class="btn btn-sm"
                          :class="row.phone ? 'btn-primary' : 'btn-ghost'"
                          :disabled="!row.phone"
                          :title="row.phone ? 'Kirim update closing via WhatsApp' : 'Lengkapi nomor HP di Data Karyawan'"
                          @click="openClosingWhatsApp(row)"
                        >Kirim WA</button>
                      </td>
                    </tr>
                    <tr v-if="!(reportResult.data?.rows || []).length"><td colspan="9">Tidak ada data.</td></tr>
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

            <template v-else-if="reportForm.type==='alur-kas'">
              <div class="report-kpi-row" style="margin-bottom:14px">
                <div class="report-kpi">
                  <span class="report-kpi-label">Transaksi</span>
                  <strong>{{ reportResult.data?.tx_count || 0 }}</strong>
                </div>
                <div class="report-kpi">
                  <span class="report-kpi-label">Pemasukan</span>
                  <strong class="value-income">{{ formatRp(reportResult.data?.total_pemasukan) }}</strong>
                </div>
                <div class="report-kpi">
                  <span class="report-kpi-label">Pengeluaran</span>
                  <strong class="value-expense">{{ formatRp(reportResult.data?.total_pengeluaran) }}</strong>
                </div>
                <div class="report-kpi">
                  <span class="report-kpi-label">Laba/Rugi</span>
                  <strong :class="amountClass(reportResult.data?.selisih)">{{ formatRp(reportResult.data?.selisih) }}</strong>
                </div>
              </div>
              <div class="table-wrap">
                <table>
                  <thead>
                    <tr>
                      <th class="col-no">No</th>
                      <th>Pos</th>
                      <th>Tipe</th>
                      <th>Qty</th>
                      <th>Total</th>
                      <th class="col-aksi">Aksi</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr class="closing-group-row"><td colspan="6"><strong>Pemasukan</strong></td></tr>
                    <tr v-for="(row, idx) in (reportResult.data?.pemasukan || [])" :key="'in'+row.category_id">
                      <td class="col-no">{{ rowNo(idx) }}</td>
                      <td>{{ row.nama }}</td>
                      <td>Pemasukan</td>
                      <td>{{ row.jumlah }}</td>
                      <td class="value-income">{{ formatRp(row.total) }}</td>
                      <td class="col-aksi">
                        <button class="btn btn-ghost btn-sm" type="button" @click="openReportAlurDetail(row)">Detail</button>
                      </td>
                    </tr>
                    <tr v-if="!(reportResult.data?.pemasukan || []).length"><td colspan="6">Tidak ada pemasukan.</td></tr>
                    <tr class="closing-subtotal-row">
                      <td colspan="4"><strong>Total Pemasukan</strong></td>
                      <td class="value-income"><strong>{{ formatRp(reportResult.data?.total_pemasukan) }}</strong></td>
                      <td></td>
                    </tr>
                    <tr class="closing-group-row"><td colspan="6"><strong>Pengeluaran</strong></td></tr>
                    <tr v-for="(row, idx) in (reportResult.data?.pengeluaran || [])" :key="'ex'+row.category_id">
                      <td class="col-no">{{ rowNo(idx) }}</td>
                      <td>{{ row.nama }}</td>
                      <td>Pengeluaran</td>
                      <td>{{ row.jumlah }}</td>
                      <td class="value-expense">{{ formatRp(row.total) }}</td>
                      <td class="col-aksi">
                        <button class="btn btn-ghost btn-sm" type="button" @click="openReportAlurDetail(row)">Detail</button>
                      </td>
                    </tr>
                    <tr v-if="!(reportResult.data?.pengeluaran || []).length"><td colspan="6">Tidak ada pengeluaran.</td></tr>
                    <tr class="closing-subtotal-row">
                      <td colspan="4"><strong>Total Pengeluaran</strong></td>
                      <td class="value-expense"><strong>{{ formatRp(reportResult.data?.total_pengeluaran) }}</strong></td>
                      <td></td>
                    </tr>
                    <tr class="closing-subtotal-row">
                      <td colspan="4"><strong>Laba / Rugi</strong></td>
                      <td><strong :class="amountClass(reportResult.data?.selisih)">{{ formatRp(reportResult.data?.selisih) }}</strong></td>
                      <td></td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <p class="closing-hint" style="margin-top:12px">
                Klik <strong>Detail</strong> untuk melihat transaksi per pos. Matriks multi-bulan ada di menu <strong>Alur Kas</strong>.
              </p>
            </template>
          </div>
        </section>

        <section v-if="page==='profit-shares' && canAccessProfitShares">
          <div class="page-head">
            <div>
              <h2 class="brand">Bagi Hasil</h2>
              <p>Laba bersih per cabang (pemasukan − pengeluaran) × % PIC — input manual tahap 1</p>
            </div>
          </div>

          <div class="card">
            <div class="filter-bar">
              <div class="field">
                <label>Cabang</label>
                <select v-model="psFilter.branch_id" @change="onPsFilterChange">
                  <option value="">Semua cabang</option>
                  <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                </select>
              </div>
              <div class="field">
                <label>Bulan</label>
                <select v-model.number="psFilter.month" @change="onPsFilterChange">
                  <option v-for="m in 12" :key="m" :value="m">{{ m }}</option>
                </select>
              </div>
              <div class="field">
                <label>Tahun</label>
                <select v-model.number="psFilter.year" @change="onPsFilterChange">
                  <option v-for="y in psYears" :key="y" :value="y">{{ y }}</option>
                </select>
              </div>
              <div class="field field-actions">
                <label>&nbsp;</label>
                <button class="btn btn-ghost btn-sm" type="button" :disabled="loading" @click="loadProfitShareBoard">Muat Ulang</button>
              </div>
            </div>

            <div class="filter-meta">
              {{ psMonthLabel }}
              · Pemasukan <strong class="value-income">{{ formatRp(psBoard.meta?.totals?.total_income || 0) }}</strong>
              · Pengeluaran <strong class="value-expense">{{ formatRp(psBoard.meta?.totals?.total_expense || 0) }}</strong>
              · Laba bersih <strong :class="Number(psBoard.meta?.totals?.net_profit || 0) < 0 ? 'value-expense' : ''">{{ formatRp(psBoard.meta?.totals?.net_profit || 0) }}</strong>
              · Bagian PIC <strong>{{ formatRp(psBoard.meta?.totals?.pic_amount || 0) }}</strong>
            </div>

            <div class="table-wrap" style="margin-bottom:16px">
              <table>
                <thead>
                  <tr>
                    <th class="col-no">No</th>
                    <th>Cabang</th>
                    <th>PIC</th>
                    <th>%</th>
                    <th>Pemasukan</th>
                    <th>Pengeluaran</th>
                    <th>Laba Bersih</th>
                    <th>Bagian PIC</th>
                    <th>Status</th>
                    <th>Aksi</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(row, idx) in (psBoard.rows || [])" :key="'ps'+row.branch_id">
                    <td class="col-no">{{ rowNo(idx) }}</td>
                    <td><strong>{{ row.branch_name }}</strong></td>
                    <td>{{ row.pic_name || '—' }}</td>
                    <td>{{ formatPctShare(row.pic_share_pct) }}</td>
                    <td class="value-income">{{ formatRp(row.total_income) }}</td>
                    <td class="value-expense">{{ formatRp(row.total_expense) }}</td>
                    <td :class="Number(row.net_profit) < 0 ? 'value-expense' : ''">{{ formatRp(row.net_profit) }}</td>
                    <td style="font-weight:600">{{ formatRp(row.pic_amount) }}</td>
                    <td>
                      <span class="badge" :class="row.status==='locked' ? 'badge-rejected' : 'badge-pending'">
                        {{ row.status==='locked' ? 'Terkunci' : 'Draft' }}
                      </span>
                    </td>
                    <td>
                      <button class="btn btn-ghost btn-sm" type="button" @click="openPsEditor(row)">Kelola</button>
                    </td>
                  </tr>
                  <tr v-if="!(psBoard.rows || []).length">
                    <td colspan="10">Tidak ada cabang.</td>
                  </tr>
                </tbody>
              </table>
            </div>

            <div v-if="psEditor.open" class="ps-editor">
              <div class="panel-title" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
                <span>Detail — {{ psEditor.branch_name }} · {{ psMonthLabel }}</span>
                <button class="btn btn-ghost btn-sm" type="button" @click="closePsEditor">Tutup</button>
              </div>

              <div class="filter-bar" style="margin-top:10px">
                <div class="field">
                  <label>Nama PIC</label>
                  <input type="text" v-model="psEditor.pic_name" :disabled="psEditor.status==='locked' || loading" placeholder="Mis. Hasmin" />
                </div>
                <div class="field" style="max-width:140px">
                  <label>% Bagi hasil</label>
                  <input
                    type="number"
                    min="0"
                    max="100"
                    step="0.01"
                    v-model.number="psEditor.pic_share_pct"
                    :disabled="psEditor.status==='locked' || loading"
                    @input="recalcPsEditor"
                  />
                </div>
                <div class="field" style="flex:1">
                  <label>Catatan</label>
                  <input type="text" v-model="psEditor.note" :disabled="psEditor.status==='locked' || loading" placeholder="Opsional" />
                </div>
              </div>

              <div class="att-actions" style="margin:10px 0 14px;flex-wrap:wrap">
                <button class="btn btn-ghost btn-sm" type="button" :disabled="loading || psEditor.status==='locked'" @click="copyProfitSharePrevious">Salin Pos Bulan Lalu</button>
                <button class="btn btn-primary btn-sm" type="button" :disabled="loading || psEditor.status==='locked'" @click="saveProfitShare">Simpan</button>
                <button class="btn btn-danger btn-sm" type="button" :disabled="loading || psEditor.status==='locked'" @click="lockProfitShare">Kunci</button>
                <button v-if="psEditor.status==='locked'" class="btn btn-ghost btn-sm" type="button" :disabled="loading" @click="unlockProfitShare">Buka Kunci</button>
              </div>

              <div class="report-kpi-row" style="margin-bottom:14px">
                <div class="report-kpi">
                  <span class="report-kpi-label">Pemasukan</span>
                  <strong class="value-income">{{ formatRp(psEditor.total_income) }}</strong>
                </div>
                <div class="report-kpi">
                  <span class="report-kpi-label">Pengeluaran</span>
                  <strong class="value-expense">{{ formatRp(psEditor.total_expense) }}</strong>
                </div>
                <div class="report-kpi">
                  <span class="report-kpi-label">Laba bersih</span>
                  <strong :class="Number(psEditor.net_profit) < 0 ? 'value-expense' : ''">{{ formatRp(psEditor.net_profit) }}</strong>
                </div>
                <div class="report-kpi">
                  <span class="report-kpi-label">Bagian PIC ({{ formatPctShare(psEditor.pic_share_pct) }})</span>
                  <strong>{{ formatRp(psEditor.pic_amount) }}</strong>
                </div>
              </div>

              <div class="grid-2">
                <div>
                  <div class="panel-title" style="margin-bottom:8px;display:flex;justify-content:space-between;align-items:center">
                    <span>Pemasukan</span>
                    <button class="btn btn-ghost btn-sm" type="button" :disabled="psEditor.status==='locked' || loading" @click="addPsLine('income')">+ Pos</button>
                  </div>
                  <div class="table-wrap">
                    <table>
                      <thead>
                        <tr><th>Pos</th><th>Nominal</th><th></th></tr>
                      </thead>
                      <tbody>
                        <tr v-for="line in psIncomeLines" :key="line.key">
                          <td>
                            <input type="text" v-model="line.name" :disabled="psEditor.status==='locked' || loading" placeholder="Nama pos" />
                          </td>
                          <td>
                            <input
                              type="text"
                              inputmode="numeric"
                              :disabled="psEditor.status==='locked' || loading"
                              :value="formatInputNumber(line.amount)"
                              @focus="onPayrollFocus"
                              @input="onPsLineAmountInput(line, $event)"
                            />
                          </td>
                          <td>
                            <button class="btn btn-ghost btn-sm" type="button" :disabled="psEditor.status==='locked' || loading" @click="removePsLine(line)">Hapus</button>
                          </td>
                        </tr>
                        <tr v-if="!psIncomeLines.length"><td colspan="3">Belum ada pos pemasukan.</td></tr>
                      </tbody>
                    </table>
                  </div>
                </div>
                <div>
                  <div class="panel-title" style="margin-bottom:8px;display:flex;justify-content:space-between;align-items:center">
                    <span>Pengeluaran</span>
                    <button class="btn btn-ghost btn-sm" type="button" :disabled="psEditor.status==='locked' || loading" @click="addPsLine('expense')">+ Pos</button>
                  </div>
                  <div class="table-wrap">
                    <table>
                      <thead>
                        <tr><th>Pos</th><th>Nominal</th><th></th></tr>
                      </thead>
                      <tbody>
                        <tr v-for="line in psExpenseLines" :key="line.key">
                          <td>
                            <input type="text" v-model="line.name" :disabled="psEditor.status==='locked' || loading" placeholder="Nama pos" />
                          </td>
                          <td>
                            <input
                              type="text"
                              inputmode="numeric"
                              :disabled="psEditor.status==='locked' || loading"
                              :value="formatInputNumber(line.amount)"
                              @focus="onPayrollFocus"
                              @input="onPsLineAmountInput(line, $event)"
                            />
                          </td>
                          <td>
                            <button class="btn btn-ghost btn-sm" type="button" :disabled="psEditor.status==='locked' || loading" @click="removePsLine(line)">Hapus</button>
                          </td>
                        </tr>
                        <tr v-if="!psExpenseLines.length"><td colspan="3">Belum ada pos pengeluaran.</td></tr>
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>
              <p class="filter-meta" style="margin-top:10px">
                Konter: template pos awal tersedia. Bengkel: pos bebas. Salin bulan lalu hanya menyalin nama pos (nominal kosong).
              </p>
            </div>
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

        <section v-if="page==='db-backup' && isOwner" class="db-backup-page">
          <div class="page-head">
            <div>
              <h2 class="brand">Backup & Restore</h2>
              <p>Kelola dump PostgreSQL — hanya Owner. Restore mengganti seluruh data.</p>
            </div>
          </div>

          <div class="card db-schedule-card">
            <div class="db-schedule-main">
              <div>
                <div class="panel-title">Jadwal otomatis</div>
                <p class="db-muted">Zona {{ dbBackupMeta.schedule?.timezone || 'Asia/Jayapura' }} · simpan max {{ dbBackupMeta.keep }} file</p>
              </div>
              <label class="db-switch">
                <input type="checkbox" v-model="dbBackupForm.scheduleEnabled" :disabled="loading || dbBackupForm.scheduleSaving" />
                <span>{{ dbBackupForm.scheduleEnabled ? 'Aktif' : 'Nonaktif' }}</span>
              </label>
              <div class="field db-schedule-time">
                <label>Jam</label>
                <input type="time" v-model="dbBackupForm.scheduleTime" :disabled="loading || dbBackupForm.scheduleSaving" />
              </div>
              <button
                class="btn btn-primary db-btn-inline"
                type="button"
                :disabled="loading || dbBackupForm.scheduleSaving"
                @click="saveDbBackupSchedule"
              >
                {{ dbBackupForm.scheduleSaving ? 'Menyimpan…' : 'Simpan Jadwal' }}
              </button>
            </div>
            <div class="db-schedule-meta" v-if="dbBackupMeta.schedule?.next_run_at || dbBackupMeta.schedule?.last_run_at">
              <span v-if="dbBackupMeta.schedule?.next_run_at">
                Berikutnya <strong>{{ formatDateTime(dbBackupMeta.schedule.next_run_at) }}</strong>
              </span>
              <span v-if="dbBackupMeta.schedule?.last_run_at">
                Terakhir
                <strong :class="dbBackupMeta.schedule.last_status === 'ok' ? 'value-income' : 'value-expense'">
                  {{ dbBackupMeta.schedule.last_status === 'ok' ? 'berhasil' : 'gagal' }}
                </strong>
                · {{ formatDateTime(dbBackupMeta.schedule.last_run_at) }}
                <template v-if="dbBackupMeta.schedule.last_filename"> · {{ dbBackupMeta.schedule.last_filename }}</template>
              </span>
            </div>
          </div>

          <div class="db-backup-grid">
            <div class="card db-action-card">
              <div class="db-card-kicker">Cadangan</div>
              <div class="panel-title">Buat backup baru</div>
              <p class="db-muted">Dump format <code>.dump</code> (pg_dump -Fc), langsung diunduh ke perangkat Anda.</p>
              <div class="form-grid">
                <div class="field">
                  <label>Kata sandi Owner</label>
                  <input
                    type="password"
                    v-model="dbBackupForm.password"
                    autocomplete="current-password"
                    placeholder="Konfirmasi kata sandi"
                    :disabled="loading || dbBackupForm.creating || dbBackupForm.uploading"
                  />
                </div>
                <button
                  class="btn btn-primary"
                  type="button"
                  :disabled="loading || dbBackupForm.creating || dbBackupForm.uploading || !dbBackupForm.password"
                  @click="createDbBackup"
                >
                  {{ dbBackupForm.creating ? 'Membuat…' : 'Buat & Unduh' }}
                </button>
              </div>
            </div>

            <div class="card db-action-card">
              <div class="db-card-kicker">Pulihkan</div>
              <div class="panel-title">Unggah file dump</div>
              <p class="db-muted">
                Unggah dump lokal ke daftar server, lalu tekan <strong>Pulihkan</strong> pada baris file.
                Maks. ~{{ Math.round((dbBackupMeta.upload_max_kb || 20480) / 1024) }} MB.
              </p>
              <div class="form-grid">
                <div class="field">
                  <label>Kata sandi Owner</label>
                  <input
                    type="password"
                    v-model="dbBackupForm.password"
                    autocomplete="current-password"
                    placeholder="Konfirmasi kata sandi"
                    :disabled="loading || dbBackupForm.creating || dbBackupForm.uploading"
                  />
                </div>
                <div class="field">
                  <label>File .dump</label>
                  <input
                    id="db-backup-upload-input"
                    type="file"
                    accept=".dump,application/octet-stream"
                    :disabled="loading || dbBackupForm.creating || dbBackupForm.uploading"
                    @change="onDbBackupFileChange"
                  />
                  <p class="db-file-name" v-if="dbBackupForm.uploadFile">{{ dbBackupForm.uploadFile.name }}</p>
                </div>
                <button
                  class="btn btn-ghost"
                  type="button"
                  :disabled="loading || dbBackupForm.creating || dbBackupForm.uploading || !dbBackupForm.password || !dbBackupForm.uploadFile"
                  @click="uploadDbBackup"
                >
                  {{ dbBackupForm.uploading ? 'Mengunggah…' : 'Unggah ke Daftar' }}
                </button>
              </div>
            </div>
          </div>

          <div class="card db-list-card">
            <div class="panel-head">
              <div>
                <div class="panel-title">Backup tersimpan</div>
                <p class="db-muted">File di server (maks. {{ dbBackupMeta.keep }} terbaru). Unduh atau pulihkan dari sini.</p>
              </div>
              <button class="btn btn-ghost btn-sm db-btn-inline" type="button" :disabled="loading" @click="loadDbBackups">Muat ulang</button>
            </div>
            <div class="table-wrap">
              <table class="db-backup-table">
                <thead>
                  <tr>
                    <th class="col-no">No</th>
                    <th>File</th>
                    <th>Ukuran</th>
                    <th>Dibuat</th>
                    <th class="col-aksi">Aksi</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(row, idx) in dbBackupList" :key="row.filename">
                    <td class="col-no">{{ rowNo(idx) }}</td>
                    <td class="desc-cell"><code class="db-filename">{{ row.filename }}</code></td>
                    <td>{{ row.size_label }}</td>
                    <td>{{ formatDateTime(row.created_at) }}</td>
                    <td class="col-aksi">
                      <div class="aksi-stack">
                        <button class="btn btn-ghost btn-sm" type="button" :disabled="loading || dbRestoreModal.restoring || !row.size" @click="downloadDbBackup(row)">Unduh</button>
                        <button class="btn btn-danger btn-sm" type="button" :disabled="loading || dbRestoreModal.restoring || !row.size" @click="openDbRestore(row)">Pulihkan</button>
                      </div>
                    </td>
                  </tr>
                  <tr v-if="!dbBackupList.length">
                    <td colspan="5" class="db-empty">Belum ada backup. Buat cadangan baru atau unggah dump di atas.</td>
                  </tr>
                </tbody>
              </table>
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

    <!-- Laporan harian transaksi -->
    <div v-if="txDailyReport.open" class="modal-backdrop" @click.self="closeTxDailyReport">
      <div class="modal modal-wide tx-daily-modal">
        <h3>Laporan Harian Transaksi</h3>
        <p class="modal-message">
          <strong>{{ formatTxDailyDateLabel(txDailyReport.date) }}</strong>
          <span class="muted"> · {{ txDailyReport.branch_name }}</span>
        </p>
        <div v-if="txDailyReport.loading" class="empty-hint">Memuat…</div>
        <template v-else>
          <div class="report-kpi-row" style="margin-bottom:12px">
            <div class="report-kpi">
              <span class="report-kpi-label">Penjualan HP (Cash)</span>
              <strong class="value-income">{{ formatRp(txDailyCashSummary.hpSalesCash) }}</strong>
            </div>
            <div class="report-kpi">
              <span class="report-kpi-label">Pulsa (Cash)</span>
              <strong class="value-income">{{ formatRp(txDailyCashSummary.pulsaSalesCash) }}</strong>
            </div>
            <div class="report-kpi">
              <span class="report-kpi-label">Keluar Cash</span>
              <strong class="value-expense">{{ formatRp(txDailyCashSummary.cashOut) }}</strong>
            </div>
            <div class="report-kpi">
              <span class="report-kpi-label">Laba/Rugi semua</span>
              <strong :class="amountClass(txDailyCashSummary.netAll)">{{ formatRp(txDailyCashSummary.netAll) }}</strong>
            </div>
            <div class="report-kpi">
              <span class="report-kpi-label">Transaksi</span>
              <strong>{{ txDailyReport.rows.length }}</strong>
            </div>
          </div>
          <div class="tx-daily-toolbar">
            <div class="field tx-daily-wa-field">
              <label>Nomor WhatsApp (opsional)</label>
              <input
                v-model="txDailyReport.wa_phone"
                type="tel"
                placeholder="Contoh: 0812… — kosongkan untuk pilih chat manual"
              />
            </div>
            <div class="tx-daily-actions-top">
              <button class="btn btn-ghost btn-sm" type="button" @click="closeTxDailyReport">Tutup</button>
              <button
                class="btn btn-primary btn-sm"
                type="button"
                :disabled="txDailyReport.loading"
                @click="shareTxDailyWhatsApp"
              >Kirim WhatsApp</button>
            </div>
          </div>
          <div class="table-wrap tx-daily-table-scroll">
            <table>
              <thead>
                <tr>
                  <th class="col-no">No</th>
                  <th>Tipe</th>
                  <th>Kategori</th>
                  <th>Akun</th>
                  <th>Nominal</th>
                  <th>Keterangan</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(row, idx) in txDailyReport.rows" :key="row.id">
                  <td class="col-no">{{ rowNo(idx) }}</td>
                  <td>{{ row.tipe === 'income' ? 'Pemasukan' : 'Pengeluaran' }}</td>
                  <td>{{ row.kategori }}</td>
                  <td>{{ row.akun }}</td>
                  <td :class="row.tipe === 'expense' ? 'value-expense' : 'value-income'">{{ formatRp(row.nominal) }}</td>
                  <td>{{ row.keterangan || '—' }}</td>
                </tr>
                <tr v-if="!txDailyReport.rows.length">
                  <td colspan="6">Belum ada transaksi pada tanggal ini.</td>
                </tr>
              </tbody>
            </table>
          </div>
        </template>
        <div v-if="txDailyReport.loading" class="modal-actions">
          <button class="btn btn-ghost" type="button" @click="closeTxDailyReport">Tutup</button>
        </div>
      </div>
    </div>

    <!-- Upah teknisi job detail modal -->
    <div v-if="reportUpahTechDetail.open" class="modal-backdrop" @click.self="closeReportUpahTechDetail">
      <div class="modal modal-wide">
        <h3>Detail Pekerjaan — {{ reportUpahTechDetail.teknisi }}</h3>
        <p class="modal-message muted">
          {{ reportUpahTechDetail.cabang }}
          · {{ reportUpahTechDetail.jumlah_job }} job
          · Gross <strong class="value-income">{{ formatRp(reportUpahTechDetail.total_gross) }}</strong>
          · Upah <strong>{{ formatRp(reportUpahTechDetail.total_net) }}</strong>
          · Toko <strong>{{ formatRp(reportUpahTechDetail.total_shop) }}</strong>
          · % Upah {{ formatPctShare(reportUpahTechDetail.tech_share_pct) }}
        </p>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th class="col-no">No</th>
                <th>Tanggal</th>
                <th>Jenis</th>
                <th>Gross</th>
                <th>%</th>
                <th>Net</th>
                <th>Keterangan</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(row, idx) in reportUpahTechDetailPagedRows" :key="'utd'+row.id">
                <td class="col-no">{{ rowNo(idx, reportUpahTechDetail.page, reportUpahTechDetail.per_page) }}</td>
                <td>{{ formatDate(row.tanggal) || row.tanggal }}</td>
                <td>{{ row.jenis }}</td>
                <td class="value-income">{{ formatRp(row.gross) }}</td>
                <td>{{ formatPctShare(row.pct) }}</td>
                <td><strong>{{ formatRp(row.net) }}</strong></td>
                <td class="desc-cell">{{ row.keterangan || '—' }}</td>
              </tr>
              <tr v-if="!reportUpahTechDetail.rows.length">
                <td colspan="7">Tidak ada job untuk teknisi ini.</td>
              </tr>
              <tr v-if="reportUpahTechDetail.rows.length" class="closing-subtotal-row">
                <td colspan="3"><strong>Total</strong></td>
                <td class="value-income"><strong>{{ formatRp(reportUpahTechDetail.total_gross) }}</strong></td>
                <td></td>
                <td><strong>{{ formatRp(reportUpahTechDetail.total_net) }}</strong></td>
                <td></td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="reportUpahTechDetailPageCount > 1" class="pager" style="margin-top:10px">
          <button
            class="btn btn-ghost btn-sm"
            type="button"
            :disabled="reportUpahTechDetail.page <= 1"
            @click="setReportUpahTechDetailPage(reportUpahTechDetail.page - 1)"
          >Sebelumnya</button>
          <span>Halaman {{ reportUpahTechDetail.page }} / {{ reportUpahTechDetailPageCount }} · {{ reportUpahTechDetail.rows.length }} job</span>
          <button
            class="btn btn-ghost btn-sm"
            type="button"
            :disabled="reportUpahTechDetail.page >= reportUpahTechDetailPageCount"
            @click="setReportUpahTechDetailPage(reportUpahTechDetail.page + 1)"
          >Berikutnya</button>
        </div>
        <div class="modal-actions">
          <button class="btn btn-primary" type="button" @click="closeReportUpahTechDetail">Tutup</button>
        </div>
      </div>
    </div>

    <!-- Ringkasan harian detail modal -->
    <div v-if="reportRingkasanDetail.open" class="modal-backdrop" @click.self="closeReportRingkasanDetail">
      <div class="modal modal-wide">
        <h3>Detail Transaksi — {{ formatDate(reportRingkasanDetail.tanggal) || reportRingkasanDetail.tanggal }}</h3>
        <p class="modal-message muted">
          Pemasukan <strong class="value-income">{{ formatRp(reportRingkasanDetail.pemasukan) }}</strong>
          · Pengeluaran <strong class="value-expense">{{ formatRp(reportRingkasanDetail.pengeluaran) }}</strong>
          · Selisih <strong>{{ formatRp(reportRingkasanDetail.selisih) }}</strong>
          · {{ reportRingkasanDetail.rows.length }} transaksi
        </p>
        <div v-if="reportRingkasanDetail.loading" class="empty-hint">Memuat…</div>
        <div v-else class="table-wrap">
          <table>
            <thead>
              <tr>
                <th class="col-no">No</th>
                <th>Kategori</th>
                <th>Tipe</th>
                <th>Akun</th>
                <th>Nominal</th>
                <th>Keterangan</th>
                <th>Input oleh</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(t, idx) in reportRingkasanDetail.rows" :key="t.id">
                <td class="col-no">{{ rowNo(idx) }}</td>
                <td>{{ t.category?.name || '—' }}</td>
                <td>{{ t.category?.type === 'income' ? 'Pemasukan' : (t.category?.type === 'expense' ? 'Pengeluaran' : '—') }}</td>
                <td>{{ t.account?.name || '—' }}</td>
                <td :class="t.category?.type === 'expense' ? 'value-expense' : 'value-income'">
                  {{ formatRp(t.amount) }}
                </td>
                <td class="desc-cell">{{ t.description || '—' }}</td>
                <td>{{ t.user?.name || '—' }}</td>
              </tr>
              <tr v-if="!reportRingkasanDetail.rows.length">
                <td colspan="7">Tidak ada transaksi pada tanggal ini.</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="modal-actions">
          <button class="btn btn-primary" type="button" @click="closeReportRingkasanDetail">Tutup</button>
        </div>
      </div>
    </div>

    <!-- Alur Kas pos detail modal -->
    <div v-if="reportAlurDetail.open" class="modal-backdrop" @click.self="closeReportAlurDetail">
      <div class="modal modal-wide">
        <h3>Detail Pos — {{ reportAlurDetail.pos || '—' }}</h3>
        <p class="modal-message muted" v-if="reportAlurDetail.meta">
          {{ reportAlurDetail.tipe === 'income' ? 'Pemasukan' : (reportAlurDetail.tipe === 'expense' ? 'Pengeluaran' : '—') }}
          · {{ reportAlurDetail.meta.cabang }}
          · {{ reportAlurDetail.meta.date_from }} s/d {{ reportAlurDetail.meta.date_to }}
          · {{ reportAlurDetail.jumlah }} transaksi
          · Total
          <strong :class="reportAlurDetail.tipe === 'expense' ? 'value-expense' : 'value-income'">
            {{ formatRp(reportAlurDetail.total) }}
          </strong>
        </p>
        <div v-if="reportAlurDetail.loading" class="empty-hint">Memuat…</div>
        <div v-else class="table-wrap">
          <table>
            <thead>
              <tr>
                <th class="col-no">No</th>
                <th>Tanggal</th>
                <th>Cabang</th>
                <th>Akun</th>
                <th>Nominal</th>
                <th>Keterangan</th>
                <th>Input oleh</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(row, idx) in reportAlurDetail.rows" :key="row.id">
                <td class="col-no">{{ rowNo(idx) }}</td>
                <td>{{ row.tanggal }}</td>
                <td>{{ row.cabang || '—' }}</td>
                <td>{{ row.akun || '—' }}</td>
                <td :class="reportAlurDetail.tipe === 'expense' ? 'value-expense' : 'value-income'">
                  {{ formatRp(row.nominal) }}
                </td>
                <td>{{ row.keterangan || '—' }}</td>
                <td>{{ row.input_oleh || '—' }}</td>
              </tr>
              <tr v-if="!reportAlurDetail.rows.length">
                <td colspan="7">Tidak ada transaksi pada pos ini.</td>
              </tr>
              <tr v-if="reportAlurDetail.rows.length" class="closing-subtotal-row">
                <td colspan="4"><strong>Total</strong></td>
                <td>
                  <strong :class="reportAlurDetail.tipe === 'expense' ? 'value-expense' : 'value-income'">
                    {{ formatRp(reportAlurDetail.total) }}
                  </strong>
                </td>
                <td colspan="2"></td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="modal-actions">
          <button class="btn btn-primary" type="button" @click="closeReportAlurDetail">Tutup</button>
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
            <div v-if="payrollDetailHas(payrollDetail.data.present_days)"><span>Hadir</span><strong>{{ payrollDetail.data.present_days }} hari</strong></div>
            <div v-if="payrollDetailHas(payrollDetail.data.gapok)"><span>Gapok</span><strong>{{ formatRp(payrollDetail.data.gapok) }}</strong></div>
            <div v-if="payrollDetail.data.is_pic && payrollDetailHas(payrollDetail.data.insentif_pic)"><span>Insentif PIC</span><strong>{{ formatRp(payrollDetail.data.insentif_pic) }}</strong></div>
            <div v-if="payrollDetailHas(payrollDetail.data.closing_qty)"><span>Qty Closing</span><strong>{{ payrollDetail.data.closing_qty }}</strong></div>
            <div v-if="payrollDetailHas(payrollDetail.data.insentif_hp)"><span>Insentif HP</span><strong>{{ formatRp(payrollDetail.data.insentif_hp) }}</strong></div>
            <div v-if="payrollDetailHas(payrollDetail.data.service_profit)"><span>Profit Service</span><strong>{{ formatRp(payrollDetail.data.service_profit) }}</strong></div>
            <div v-if="payrollDetailHas(payrollDetail.data.service_incentive)"><span>Service 50%</span><strong>{{ formatRp(payrollDetail.data.service_incentive) }}</strong></div>
            <div v-if="payrollDetailHas(payrollDetail.data.insentif_acc)"><span>Insentif ACC</span><strong>{{ formatRp(payrollDetail.data.insentif_acc) }}</strong></div>
            <div v-if="payrollDetailHas(payrollDetail.data.bonus_absen)"><span>Bonus</span><strong>{{ formatRp(payrollDetail.data.bonus_absen) }}</strong></div>
            <div v-if="payrollDetailHas(payrollDetail.data.hutang)"><span>Hutang</span><strong>{{ formatRp(payrollDetail.data.hutang) }}</strong></div>
            <div v-if="payrollDetailHas(payrollDetail.data.kasbon != null ? payrollDetail.data.kasbon : payrollDetail.data.pengeluaran)"><span>Kasbon</span><strong>{{ formatRp(payrollDetail.data.kasbon != null ? payrollDetail.data.kasbon : payrollDetail.data.pengeluaran) }}</strong></div>
            <div class="payroll-detail-total"><span>Total bersih</span><strong :class="Number(payrollDetail.data.total) < 0 ? 'value-expense' : ''">{{ formatRp(payrollDetail.data.total) }}</strong></div>
            <div v-if="payrollDetail.data.status==='locked'">
              <span>Status bayar</span>
              <strong :class="payrollDetail.data.is_paid ? 'value-income' : 'value-expense'">
                {{ payrollDetail.data.is_paid ? 'Sudah dibayar' : 'Belum dibayar' }}
              </strong>
            </div>
          </div>
          <template v-if="(payrollDetail.services || []).length">
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
                </tbody>
              </table>
            </div>
          </template>
        </template>
        <div class="modal-actions">
          <button class="btn btn-ghost" type="button" @click="closePayrollDetail">Tutup</button>
          <button
            v-if="payrollDetail.data"
            class="btn btn-ghost"
            type="button"
            :disabled="!payrollDetail.data.phone"
            title="Buka WhatsApp ke nomor karyawan"
            @click="openPayrollWhatsApp(payrollDetail.data)"
          >Kirim WhatsApp</button>
          <button
            v-if="payrollDetail.data && payrollDetail.data.status==='locked' && !payrollDetail.data.is_paid"
            class="btn btn-primary"
            type="button"
            :disabled="loading"
            @click="markPayrollPaid(payrollDetail.data)"
          >Tandai Dibayar</button>
          <button
            v-if="payrollDetail.data && payrollDetail.data.status==='locked' && payrollDetail.data.is_paid"
            class="btn btn-ghost"
            type="button"
            :disabled="loading"
            @click="markPayrollUnpaid(payrollDetail.data)"
          >Batalkan Bayar</button>
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

    <!-- Restore database modal -->
    <div v-if="dbRestoreModal.open" class="modal-backdrop" @click.self="closeDbRestore">
      <div class="modal db-restore-modal">
        <h3>Pulihkan Database</h3>
        <p class="modal-message">Anda akan mengganti <strong>seluruh data</strong> dengan isi file berikut:</p>
        <div class="modal-change"><code class="db-filename">{{ dbRestoreModal.filename }}</code></div>
        <div class="form-grid" style="margin-top:14px">
          <div class="field">
            <label>Kata sandi Owner</label>
            <input
              type="password"
              v-model="dbRestoreModal.password"
              autocomplete="current-password"
              placeholder="Kata sandi akun Owner"
              :disabled="dbRestoreModal.restoring"
            />
          </div>
          <div class="field">
            <label>Ketik {{ dbBackupMeta.confirm_phrase || 'PULIHKAN' }} untuk konfirmasi</label>
            <input
              type="text"
              v-model="dbRestoreModal.confirm_phrase"
              :placeholder="dbBackupMeta.confirm_phrase || 'PULIHKAN'"
              autocomplete="off"
              :disabled="dbRestoreModal.restoring"
            />
          </div>
        </div>
        <div class="modal-actions">
          <button class="btn btn-ghost db-btn-inline" type="button" :disabled="dbRestoreModal.restoring" @click="closeDbRestore">Batal</button>
          <button
            class="btn btn-danger db-btn-inline"
            type="button"
            :disabled="dbRestoreModal.restoring || !dbRestoreModal.password || !dbRestoreModal.confirm_phrase"
            @click="confirmDbRestore"
          >{{ dbRestoreModal.restoring ? 'Memulihkan…' : 'Pulihkan Sekarang' }}</button>
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
});

try {
  app.mount('#app');
} catch (err) {
  const el = document.getElementById('app');
  const msg = (err && err.stack) ? err.stack : String(err);
  if (el) {
    el.innerHTML = '<pre style="white-space:pre-wrap;padding:24px;color:#B91C1C;font:14px/1.45 monospace;background:#FEF2F2">'
      + 'Gagal memuat aplikasi BMS:\n\n' + msg + '</pre>';
  }
  console.error(err);
}
