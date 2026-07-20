<!DOCTYPE html>
<html lang="id">
<head>
  <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
  <title>{{ $meta['judul'] }}</title>
  <style>
    @page { margin: 24px 22px; }
    body {
      font-family: DejaVu Sans, sans-serif;
      font-size: 11px;
      color: #111827;
      margin: 0;
      padding: 0;
    }
    .header {
      border-bottom: 2px solid #0F766E;
      padding-bottom: 10px;
      margin-bottom: 12px;
    }
    .brand {
      font-size: 10px;
      font-weight: bold;
      color: #0F766E;
      letter-spacing: 1px;
      text-transform: uppercase;
      margin: 0 0 4px;
    }
    h1 {
      font-size: 18px;
      margin: 0 0 4px;
      color: #0F172A;
    }
    .header-sub {
      font-size: 11px;
      color: #334155;
      margin: 0;
    }
    .meta-box {
      width: 100%;
      border-collapse: collapse;
      margin: 0 0 14px;
      background: #F8FAFC;
    }
    .meta-box td {
      border: 1px solid #CBD5E1;
      padding: 6px 8px;
      vertical-align: top;
    }
    .meta-box .lbl {
      width: 120px;
      font-weight: bold;
      color: #475569;
      background: #F1F5F9;
    }
    table.data { width: 100%; border-collapse: collapse; margin-top: 8px; }
    table.data th, table.data td { border: 1px solid #CBD5E1; padding: 5px 6px; text-align: left; }
    table.data th { background: #E2E8F0; font-size: 10px; text-transform: uppercase; color: #1E293B; }
    .right { text-align: right; }
    .income { color: #047857; }
    .expense { color: #B91C1C; }
    .summary { width: 100%; margin-bottom: 12px; border-collapse: collapse; }
    .summary td { padding: 8px; border: 1px solid #CBD5E1; width: 33%; background: #FFF; }
    .summary .label { font-size: 9px; text-transform: uppercase; color: #64748B; }
    .summary .value { font-size: 13px; font-weight: bold; margin-top: 2px; }
    .footer { margin-top: 16px; color: #64748B; font-size: 9px; border-top: 1px solid #E2E8F0; padding-top: 8px; }
    .empty { padding: 12px; border: 1px dashed #CBD5E1; color: #64748B; }
  </style>
</head>
<body>
  <div class="header">
    <div class="brand">BMS — Belawa Management System</div>
    <h1>{{ $meta['judul'] ?? 'Laporan' }}</h1>
    <p class="header-sub">Periode: {{ $meta['periode'] ?? '-' }} | Cabang: {{ $meta['cabang'] ?? '-' }}</p>
  </div>

  <table class="meta-box">
    <tr>
      <td class="lbl">Jenis Laporan</td>
      <td>{{ $meta['judul'] ?? '-' }}</td>
      <td class="lbl">Cabang</td>
      <td>{{ $meta['cabang'] ?? '-' }}</td>
    </tr>
    <tr>
      <td class="lbl">Periode</td>
      <td>{{ $meta['periode'] ?? '-' }}</td>
      <td class="lbl">Tipe</td>
      <td>{{ $meta['tipe'] ?? '-' }}</td>
    </tr>
    <tr>
      <td class="lbl">Kategori</td>
      <td>{{ $meta['kategori'] ?? '-' }}</td>
      <td class="lbl">Akun</td>
      <td>{{ $meta['akun'] ?? '-' }}</td>
    </tr>
    <tr>
      <td class="lbl">Dibuat</td>
      <td colspan="3">{{ $meta['dibuat_pada'] ?? '-' }} oleh {{ $meta['dibuat_oleh'] ?? '-' }}</td>
    </tr>
  </table>

  @php
    $hasRows = false;
    if (is_array($data ?? null)) {
      if (!empty($data['rows'])) $hasRows = true;
      if (!empty($data['harian'])) $hasRows = true;
      if (($data['jumlah'] ?? 0) > 0) $hasRows = true;
      if (($data['jumlah_karyawan'] ?? 0) > 0 && !empty($data['rows'])) $hasRows = true;
    }
  @endphp
  @unless ($hasRows)
    <div class="empty" style="margin-bottom:12px;background:#FFFBEB;border-color:#F59E0B;color:#92400E">
      Tidak ada data pada filter/periode ini. Header laporan tetap dicetak sebagai bukti cetak kosong.
    </div>
  @endunless

  @if ($type === 'ringkasan')
    @php $r = $data['ringkasan'] ?? ['pemasukan'=>0,'pengeluaran'=>0,'selisih'=>0,'jumlah_pemasukan'=>0,'jumlah_pengeluaran'=>0]; @endphp
    <table class="summary">
      <tr>
        <td>
          <div class="label">Pemasukan</div>
          <div class="value income">Rp {{ number_format($r['pemasukan'], 0, ',', '.') }}</div>
          <div>{{ $r['jumlah_pemasukan'] }} transaksi</div>
        </td>
        <td>
          <div class="label">Pengeluaran</div>
          <div class="value expense">Rp {{ number_format($r['pengeluaran'], 0, ',', '.') }}</div>
          <div>{{ $r['jumlah_pengeluaran'] }} transaksi</div>
        </td>
        <td>
          <div class="label">Selisih</div>
          <div class="value">Rp {{ number_format($r['selisih'], 0, ',', '.') }}</div>
        </td>
      </tr>
    </table>
    <table class="data">
      <thead>
        <tr><th>Tanggal</th><th class="right">Pemasukan</th><th class="right">Pengeluaran</th><th class="right">Selisih</th></tr>
      </thead>
      <tbody>
        @forelse (($data['harian'] ?? []) as $row)
          <tr>
            <td>{{ $row['tanggal'] }}</td>
            <td class="right income">{{ number_format($row['pemasukan'], 0, ',', '.') }}</td>
            <td class="right expense">{{ number_format($row['pengeluaran'], 0, ',', '.') }}</td>
            <td class="right">{{ number_format($row['selisih'], 0, ',', '.') }}</td>
          </tr>
        @empty
          <tr><td colspan="4">Tidak ada data pada periode ini.</td></tr>
        @endforelse
      </tbody>
    </table>

  @elseif ($type === 'kategori')
    <table class="data">
      <thead>
        <tr><th>Kategori</th><th>Tipe</th><th class="right">Jumlah</th><th class="right">Total</th></tr>
      </thead>
      <tbody>
        @forelse (($data['rows'] ?? []) as $row)
          <tr>
            <td>{{ $row['nama'] }}</td>
            <td>{{ $row['tipe'] === 'income' ? 'Pemasukan' : 'Pengeluaran' }}</td>
            <td class="right">{{ $row['jumlah'] }}</td>
            <td class="right {{ $row['tipe'] === 'income' ? 'income' : 'expense' }}">
              {{ number_format($row['total'], 0, ',', '.') }}
            </td>
          </tr>
        @empty
          <tr><td colspan="4">Tidak ada data pada periode ini.</td></tr>
        @endforelse
      </tbody>
    </table>
    <p>Total pemasukan: <strong class="income">Rp {{ number_format($data['total_pemasukan'] ?? 0, 0, ',', '.') }}</strong>
      | Total pengeluaran: <strong class="expense">Rp {{ number_format($data['total_pengeluaran'] ?? 0, 0, ',', '.') }}</strong></p>

  @elseif ($type === 'akun')
    @if (($data['mode'] ?? '') === 'branch')
      <table class="data">
        <thead><tr><th>Akun</th><th class="right">Saldo (s.d. akhir periode)</th></tr></thead>
        <tbody>
          @forelse (($data['rows'] ?? []) as $row)
            <tr>
              <td>{{ $row['nama_akun'] }}</td>
              <td class="right">{{ number_format($row['saldo'], 0, ',', '.') }}</td>
            </tr>
          @empty
            <tr><td colspan="2">Tidak ada data.</td></tr>
          @endforelse
        </tbody>
      </table>
    @else
      @forelse (($data['rows'] ?? []) as $branch)
        <h3 style="margin:12px 0 6px">{{ $branch['nama_cabang'] }}</h3>
        <table class="data">
          <thead><tr><th>Akun</th><th class="right">Saldo</th></tr></thead>
          <tbody>
            @foreach ($branch['akun'] as $row)
              <tr>
                <td>{{ $row['nama_akun'] }}</td>
                <td class="right">{{ number_format($row['saldo'], 0, ',', '.') }}</td>
              </tr>
            @endforeach
            <tr>
              <td><strong>Total</strong></td>
              <td class="right"><strong>{{ number_format($branch['total_saldo'], 0, ',', '.') }}</strong></td>
            </tr>
          </tbody>
        </table>
      @empty
        <div class="empty">Tidak ada data cabang.</div>
      @endforelse
    @endif
    <p>Total saldo: <strong>Rp {{ number_format($data['total_saldo'] ?? 0, 0, ',', '.') }}</strong></p>

  @elseif ($type === 'transaksi')
    <p>{{ $data['jumlah'] ?? 0 }} transaksi | Pemasukan Rp {{ number_format($data['total_pemasukan'] ?? 0, 0, ',', '.') }}
      | Pengeluaran Rp {{ number_format($data['total_pengeluaran'] ?? 0, 0, ',', '.') }}
      | Selisih Rp {{ number_format($data['selisih'] ?? 0, 0, ',', '.') }}</p>
    <table class="data">
      <thead>
        <tr>
          <th>Tanggal</th><th>Cabang</th><th>Kategori</th><th>Akun</th>
          <th class="right">Nominal</th><th>Keterangan</th>
        </tr>
      </thead>
      <tbody>
        @forelse (($data['rows'] ?? []) as $row)
          <tr>
            <td>{{ $row['tanggal'] }}</td>
            <td>{{ $row['cabang'] }}</td>
            <td>{{ $row['kategori'] }}</td>
            <td>{{ $row['akun'] }}</td>
            <td class="right {{ $row['tipe'] === 'income' ? 'income' : 'expense' }}">
              {{ number_format($row['nominal'], 0, ',', '.') }}
            </td>
            <td>{{ $row['keterangan'] ?: '-' }}</td>
          </tr>
        @empty
          <tr><td colspan="6">Tidak ada data pada periode ini.</td></tr>
        @endforelse
      </tbody>
    </table>

  @elseif ($type === 'transfer')
    <p>{{ $data['jumlah'] ?? 0 }} transfer | Total Rp {{ number_format($data['total_nominal'] ?? 0, 0, ',', '.') }}
      | Disetujui {{ $data['approved'] ?? 0 }} | Pending {{ $data['pending'] ?? 0 }} | Ditolak {{ $data['rejected'] ?? 0 }}</p>
    <table class="data">
      <thead>
        <tr><th>Tanggal</th><th>Dari</th><th>Ke</th><th>Akun</th><th class="right">Nominal</th><th>Status</th><th>Pemohon</th></tr>
      </thead>
      <tbody>
        @forelse (($data['rows'] ?? []) as $row)
          <tr>
            <td>{{ $row['tanggal'] }}</td>
            <td>{{ $row['dari'] }}</td>
            <td>{{ $row['ke'] }}</td>
            <td>{{ $row['akun'] ?: '-' }}</td>
            <td class="right">{{ number_format($row['nominal'], 0, ',', '.') }}</td>
            <td>{{ strtoupper($row['status']) }}</td>
            <td>{{ $row['pemohon'] }}</td>
          </tr>
        @empty
          <tr><td colspan="7">Tidak ada data pada periode ini.</td></tr>
        @endforelse
      </tbody>
    </table>

  @elseif ($type === 'servis')
    <p>{{ $data['jumlah'] ?? 0 }} job | Omzet Rp {{ number_format($data['total_harga'] ?? 0, 0, ',', '.') }}
      | Modal Rp {{ number_format($data['total_modal'] ?? 0, 0, ',', '.') }}
      | Profit Rp {{ number_format($data['total_profit'] ?? 0, 0, ',', '.') }}</p>
    <table class="data">
      <thead>
        <tr>
          <th>Tanggal</th><th>Cabang</th><th>Teknisi</th><th>Merek</th><th>Tipe</th><th>Kerusakan</th>
          <th class="right">Modal</th><th class="right">Harga</th><th class="right">Profit</th>
        </tr>
      </thead>
      <tbody>
        @forelse (($data['rows'] ?? []) as $row)
          <tr>
            <td>{{ $row['tanggal'] }}</td>
            <td>{{ $row['cabang'] }}</td>
            <td>{{ $row['teknisi'] }}</td>
            <td>{{ $row['merek'] }}</td>
            <td>{{ $row['tipe'] }}</td>
            <td>{{ $row['kerusakan'] }}</td>
            <td class="right">{{ number_format($row['modal'], 0, ',', '.') }}</td>
            <td class="right income">{{ number_format($row['harga'], 0, ',', '.') }}</td>
            <td class="right">{{ number_format($row['profit'], 0, ',', '.') }}</td>
          </tr>
        @empty
          <tr><td colspan="9">Tidak ada data pada periode ini.</td></tr>
        @endforelse
      </tbody>
    </table>

  @elseif ($type === 'absensi')
    <p>Hadir {{ $data['total_hadir'] ?? 0 }} · Izin {{ $data['total_izin'] ?? 0 }}
      · Sakit {{ $data['total_sakit'] ?? 0 }} · Alpha {{ $data['total_alpha'] ?? 0 }}</p>
    <table class="data">
      <thead>
        <tr><th>Karyawan</th><th>Cabang</th><th class="right">H</th><th class="right">I</th><th class="right">S</th><th class="right">A</th><th class="right">Total</th></tr>
      </thead>
      <tbody>
        @forelse (($data['rows'] ?? []) as $row)
          <tr>
            <td>{{ $row['nama'] }}</td>
            <td>{{ $row['cabang'] }}</td>
            <td class="right">{{ $row['hadir'] }}</td>
            <td class="right">{{ $row['izin'] }}</td>
            <td class="right">{{ $row['sakit'] }}</td>
            <td class="right">{{ $row['alpha'] }}</td>
            <td class="right">{{ $row['total'] }}</td>
          </tr>
        @empty
          <tr><td colspan="7">Tidak ada data pada periode ini.</td></tr>
        @endforelse
      </tbody>
    </table>

  @elseif ($type === 'gaji')
    <p>{{ $data['periode_label'] ?? '' }} · {{ $data['jumlah'] ?? 0 }} karyawan
      · Total Rp {{ number_format($data['total_gaji'] ?? 0, 0, ',', '.') }}
      · Draft {{ $data['draft'] ?? 0 }} · Locked {{ $data['locked'] ?? 0 }}</p>
    <table class="data">
      <thead>
        <tr>
          <th>Cabang</th><th>Karyawan</th><th>Status</th><th class="right">Hadir</th>
          <th class="right">Gapok</th><th class="right">HP</th><th class="right">Service</th>
          <th class="right">Acc</th><th class="right">Bonus</th><th class="right">Hutang</th><th class="right">Total</th>
        </tr>
      </thead>
      <tbody>
        @forelse (($data['rows'] ?? []) as $row)
          <tr>
            <td>{{ $row['cabang'] }}</td>
            <td>{{ $row['karyawan'] }}</td>
            <td>{{ strtoupper($row['status']) }}</td>
            <td class="right">{{ $row['hadir'] }}</td>
            <td class="right">{{ number_format($row['gapok'], 0, ',', '.') }}</td>
            <td class="right">{{ number_format($row['insentif_hp'], 0, ',', '.') }}</td>
            <td class="right">{{ number_format($row['insentif_service'], 0, ',', '.') }}</td>
            <td class="right">{{ number_format($row['acc'], 0, ',', '.') }}</td>
            <td class="right">{{ number_format($row['bonus'], 0, ',', '.') }}</td>
            <td class="right expense">{{ number_format($row['hutang'], 0, ',', '.') }}</td>
            <td class="right"><strong>{{ number_format($row['total'], 0, ',', '.') }}</strong></td>
          </tr>
        @empty
          <tr><td colspan="11">Tidak ada data gaji untuk bulan ini.</td></tr>
        @endforelse
      </tbody>
    </table>

  @elseif ($type === 'upah')
    <p>{{ $data['jumlah'] ?? 0 }} job · Gross Rp {{ number_format($data['total_gross'] ?? 0, 0, ',', '.') }}
      · Upah teknisi Rp {{ number_format($data['total_net'] ?? 0, 0, ',', '.') }}
      · Bagian toko Rp {{ number_format($data['total_shop'] ?? 0, 0, ',', '.') }}</p>
    <table class="data">
      <thead>
        <tr>
          <th>Tanggal</th><th>Cabang</th><th>Teknisi</th><th>Jenis</th>
          <th class="right">Gross</th><th class="right">%</th><th class="right">Net</th><th>Ket.</th>
        </tr>
      </thead>
      <tbody>
        @forelse (($data['rows'] ?? []) as $row)
          <tr>
            <td>{{ $row['tanggal'] }}</td>
            <td>{{ $row['cabang'] }}</td>
            <td>{{ $row['teknisi'] }}</td>
            <td>{{ $row['jenis'] }}</td>
            <td class="right">{{ number_format($row['gross'], 0, ',', '.') }}</td>
            <td class="right">{{ number_format($row['pct'], 1, ',', '.') }}%</td>
            <td class="right">{{ number_format($row['net'], 0, ',', '.') }}</td>
            <td>{{ $row['keterangan'] ?: '-' }}</td>
          </tr>
        @empty
          <tr><td colspan="8">Tidak ada data pada periode ini.</td></tr>
        @endforelse
      </tbody>
    </table>

  @elseif ($type === 'closing')
    <p>{{ $data['periode_label'] ?? '' }} · Closing {{ $data['total_qty'] ?? 0 }} / Target {{ $data['total_target'] ?? 0 }}
      @if(($data['pct'] ?? null) !== null) ({{ $data['pct'] }}%) @endif</p>
    <table class="data">
      <thead>
        <tr><th>Karyawan</th><th>Cabang</th><th class="right">Closing</th><th class="right">Target</th><th class="right">%</th><th class="right">Selisih</th></tr>
      </thead>
      <tbody>
        @forelse (($data['rows'] ?? []) as $row)
          <tr>
            <td>{{ $row['nama'] }}</td>
            <td>{{ $row['cabang'] }}</td>
            <td class="right">{{ $row['qty'] }}</td>
            <td class="right">{{ $row['target'] }}</td>
            <td class="right">{{ $row['pct'] !== null ? $row['pct'].'%' : '-' }}</td>
            <td class="right">{{ $row['selisih'] }}</td>
          </tr>
        @empty
          <tr><td colspan="6">Tidak ada data.</td></tr>
        @endforelse
      </tbody>
    </table>

  @elseif ($type === 'rekonsiliasi')
    <p>{{ $data['jumlah'] ?? 0 }} cek · Ada selisih {{ $data['ada_selisih'] ?? 0 }}
      · Total selisih Rp {{ number_format($data['total_selisih'] ?? 0, 0, ',', '.') }}</p>
    <table class="data">
      <thead>
        <tr>
          <th>Tanggal</th><th>Cabang</th><th>Akun</th>
          <th class="right">Sistem</th><th class="right">Fisik</th><th class="right">Selisih</th><th>Oleh</th>
        </tr>
      </thead>
      <tbody>
        @forelse (($data['rows'] ?? []) as $row)
          <tr>
            <td>{{ $row['tanggal'] }}</td>
            <td>{{ $row['cabang'] }}</td>
            <td>{{ $row['akun'] }}</td>
            <td class="right">{{ number_format($row['sistem'], 0, ',', '.') }}</td>
            <td class="right">{{ number_format($row['fisik'], 0, ',', '.') }}</td>
            <td class="right {{ abs($row['selisih']) >= 0.01 ? 'expense' : 'income' }}">{{ number_format($row['selisih'], 0, ',', '.') }}</td>
            <td>{{ $row['oleh'] }}</td>
          </tr>
        @empty
          <tr><td colspan="7">Tidak ada data pada periode ini.</td></tr>
        @endforelse
      </tbody>
    </table>
  @else
    <div class="empty">Jenis laporan tidak dikenali.</div>
  @endif

  <div class="footer">Dokumen digenerate otomatis oleh BMS — Belawa Management System. Periode {{ $meta['periode_raw'] ?? $meta['periode'] }}.</div>
</body>
</html>
