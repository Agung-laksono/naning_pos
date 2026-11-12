<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { getStoreInitials } from '@/utils/store';

const props = defineProps({
    canResetPassword: {
        type: Boolean,
        default: false,
    },
    status: {
        type: String,
        default: null,
    },
    cashiers: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();
const storeName = computed(() => page.props.store?.name || 'Rozak Mart');
const storeSubtitle = computed(
    () => page.props.store?.description || page.props.store?.address || 'Sistem Kasir Tablet Touch'
);
const storeInitials = computed(() => getStoreInitials(storeName.value));

const form = useForm({
    name: '',
    password: '',
    remember: true,
});

const showPassword = ref(false);
const passwordInput = ref(null);
const currentTime = ref('');
let clockTimer = null;

function updateClock() {
    const now = new Date();
    const h = String(now.getHours()).padStart(2, '0');
    const m = String(now.getMinutes()).padStart(2, '0');
    currentTime.value = `${h}:${m}`;
}

onMounted(() => {
    updateClock();
    clockTimer = setInterval(updateClock, 1000);
    // Jika ada kasir pertama, otomatis pilih sebagai default bila form masih kosong
    if (props.cashiers && props.cashiers.length > 0 && !form.name) {
        form.name = props.cashiers[0].name;
    }
});

onUnmounted(() => {
    if (clockTimer) clearInterval(clockTimer);
});

const selectCashier = (cashierName) => {
    form.name = cashierName;
    form.clearErrors('name');
    if (passwordInput.value) {
        passwordInput.value.focus();
    }
};

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <!-- Kontainer Presisi Tablet Landscape (Dioptimalkan untuk 1097 x 686) -->
    <div class="h-screen w-screen bg-slate-950 flex items-center justify-center p-3 sm:p-5 select-none overflow-hidden relative">
        <Head :title="`Login Kasir - ${storeName}`" />

        <!-- Background Glow Aksen POS -->
        <div class="absolute -top-24 -left-24 w-80 h-80 bg-blue-600/20 rounded-full blur-3xl pointer-events-none"></div>
        <div class="absolute -bottom-24 -right-24 w-80 h-80 bg-indigo-600/20 rounded-full blur-3xl pointer-events-none"></div>

        <!-- Kartu Utama Split Landscape 2-Kolom (Pas 1097 x 686 tanpa scroll vertikal) -->
        <div class="w-full max-w-[1040px] h-full max-h-[640px] bg-white rounded-3xl shadow-2xl border border-slate-800/80 overflow-hidden flex flex-col md:flex-row relative z-10">
            
            <!-- ══════════════════════════════════════════════════════════
                 KOLOM KIRI (42%): IDENTITAS TOKO & DAFTAR KASIR CEPAT
                 ══════════════════════════════════════════════════════════ -->
            <div class="w-full md:w-[42%] bg-gradient-to-br from-slate-900 via-slate-900 to-slate-950 text-white p-6 sm:p-7 flex flex-col justify-between border-b md:border-b-0 md:border-r border-slate-800 shrink-0">
                
                <!-- Header Toko & Waktu -->
                <div>
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center gap-3">
                            <div class="w-12 h-12 rounded-2xl bg-gradient-to-tr from-blue-600 to-indigo-500 text-white flex items-center justify-center font-black text-xl shadow-lg shadow-blue-500/30 border border-white/20">
                                {{ storeInitials }}
                            </div>
                            <div>
                                <h1 class="text-lg font-black text-white tracking-wide uppercase leading-tight">{{ storeName }}</h1>
                                <p class="text-[10px] text-blue-400 font-bold uppercase tracking-wider line-clamp-2">{{ storeSubtitle }}</p>
                            </div>
                        </div>
                        <span class="text-xs font-mono font-bold bg-slate-800/90 text-slate-300 px-2.5 py-1 rounded-xl border border-slate-700">
                            {{ currentTime }}
                        </span>
                    </div>

                    <p class="text-xs text-slate-400 leading-relaxed mb-4">
                        Selamat datang! Silakan pilih kasir yang bertugas atau masukkan nama dan kata sandi untuk membuka meja kasir.
                    </p>

                    <!-- Daftar Kartu Kasir Cepat (Touch Friendly) -->
                    <div class="space-y-1.5">
                        <div class="text-[10px] font-black uppercase tracking-wider text-slate-400 mb-2 flex items-center justify-between">
                            <span>Pilih Kasir Bertugas:</span>
                            <span class="text-emerald-400 font-bold">● Sistem Siap</span>
                        </div>

                        <div class="space-y-2 max-h-[220px] overflow-y-auto pr-1">
                            <div
                                v-for="c in (cashiers && cashiers.length > 0 ? cashiers : [{ id: 1, name: 'Siska Anggraeni' }])"
                                :key="c.id"
                                @click="selectCashier(c.name)"
                                :class="form.name === c.name 
                                    ? 'bg-blue-600 text-white border-blue-500 shadow-lg shadow-blue-600/30 ring-2 ring-blue-400/40' 
                                    : 'bg-slate-800/80 hover:bg-slate-800 text-slate-200 border-slate-700/80'"
                                class="p-2.5 rounded-2xl border transition-all cursor-pointer flex items-center justify-between active:scale-[0.98] select-none"
                            >
                                <div class="flex items-center gap-2.5">
                                    <div 
                                        :class="form.name === c.name ? 'bg-white text-blue-700' : (c.role === 'admin' ? 'bg-amber-500 text-slate-950 font-black' : 'bg-slate-700 text-white')"
                                        class="w-9 h-9 rounded-xl flex items-center justify-center font-black text-sm shadow-xs shrink-0"
                                    >
                                        {{ c.role === 'admin' ? '👑' : (c.name || 'K').charAt(0).toUpperCase() }}
                                    </div>
                                    <div class="leading-tight">
                                        <div class="flex items-center gap-1.5">
                                            <span class="text-xs font-black uppercase tracking-wide">{{ c.name }}</span>
                                            <span v-if="c.role === 'admin'" class="text-[8px] bg-amber-400 text-amber-950 font-black px-1.5 py-0.2 rounded font-mono">OWNER</span>
                                        </div>
                                        <div 
                                            :class="form.name === c.name ? 'text-blue-100' : 'text-slate-400'"
                                            class="text-[10px] font-medium"
                                        >
                                            {{ c.role === 'admin' ? 'Owner / Super Admin' : 'Kasir Bertugas' }}
                                        </div>
                                    </div>
                                </div>
                                <span 
                                    v-if="form.name === c.name" 
                                    class="text-xs bg-white/20 text-white px-2 py-0.5 rounded-lg font-bold"
                                >
                                    Dipilih ✓
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Footer Kiri: Pintasan Layar Pembeli (CFD) -->
                <div class="pt-4 border-t border-slate-800/80 flex items-center justify-between text-xs">
                    <span class="text-[11px] text-slate-400 font-medium">Pilih kasir di atas lalu masukkan sandi</span>
                    <a
                        :href="route('pos.display')"
                        target="_blank"
                        class="text-[11px] text-slate-400 hover:text-white transition flex items-center gap-1.5"
                        title="Buka Layar Pembeli (Dual Display)"
                    >
                        <span>🖥️</span>
                        <span>Layar Pembeli (CFD)</span>
                    </a>
                </div>

            </div>

            <!-- ══════════════════════════════════════════════════════════
                 KOLOM KANAN (58%): FORMULIR LOGIN KASIR (TOUCH OPTIMIZED)
                 ══════════════════════════════════════════════════════════ -->
            <div class="w-full md:w-[58%] p-6 sm:p-9 flex flex-col justify-center bg-white overflow-y-auto">
                
                <div class="max-w-md w-full mx-auto">
                    <!-- Judul Form -->
                    <div class="mb-5">
                        <h2 class="text-xl font-black text-slate-900 tracking-tight uppercase">Buka Meja Kasir</h2>
                        <p class="text-xs text-slate-500 font-medium mt-0.5">
                            Masukkan nama kasir dan kata sandi untuk memulai transaksi penjualan.
                        </p>
                    </div>

                    <!-- Flash Message Status -->
                    <div v-if="status" class="mb-4 p-2.5 rounded-xl bg-emerald-50 border border-emerald-200 text-xs text-emerald-800 font-medium flex items-center gap-2">
                        <span>✓</span>
                        <span>{{ status }}</span>
                    </div>

                    <!-- Form Login -->
                    <form @submit.prevent="submit" class="space-y-4">
                        
                        <!-- Input 1: Nama Kasir -->
                        <div>
                            <label for="name" class="block text-xs font-bold text-slate-800 uppercase tracking-wider mb-1">
                                Nama Kasir
                            </label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400 text-sm">
                                    👤
                                </span>
                                <input
                                    id="name"
                                    type="text"
                                    v-model="form.name"
                                    required
                                    placeholder="Contoh: Siska Anggraeni"
                                    class="w-full pl-10 pr-4 py-2.5 sm:py-3 rounded-xl border border-slate-300 text-slate-900 placeholder:text-slate-400 text-sm font-semibold focus:ring-2 focus:ring-blue-600 focus:border-blue-600 transition outline-none"
                                    :class="{ 'border-rose-400 bg-rose-50/30': form.errors.name }"
                                />
                            </div>
                            <p v-if="form.errors.name" class="mt-1 text-xs text-rose-600 font-medium">
                                {{ form.errors.name }}
                            </p>
                        </div>

                        <!-- Input 2: Kata Sandi / PIN -->
                        <div>
                            <div class="flex items-center justify-between mb-1">
                                <label for="password" class="block text-xs font-bold text-slate-800 uppercase tracking-wider">
                                    Kata Sandi / PIN
                                </label>
                                <button
                                    type="button"
                                    @click="showPassword = !showPassword"
                                    class="text-[11px] text-blue-600 hover:text-blue-700 font-bold cursor-pointer"
                                >
                                    {{ showPassword ? 'Sembunyikan' : 'Lihat Sandi' }}
                                </button>
                            </div>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400 text-sm">
                                    🔒
                                </span>
                                <input
                                    id="password"
                                    ref="passwordInput"
                                    :type="showPassword ? 'text' : 'password'"
                                    v-model="form.password"
                                    required
                                    autocomplete="current-password"
                                    placeholder="Masukkan kata sandi kasir"
                                    class="w-full pl-10 pr-10 py-2.5 sm:py-3 rounded-xl border border-slate-300 text-slate-900 placeholder:text-slate-400 text-sm font-semibold focus:ring-2 focus:ring-blue-600 focus:border-blue-600 transition outline-none font-mono"
                                    :class="{ 'border-rose-400 bg-rose-50/30': form.errors.password }"
                                />
                            </div>
                            <p v-if="form.errors.password" class="mt-1 text-xs text-rose-600 font-medium">
                                {{ form.errors.password }}
                            </p>
                        </div>

                        <!-- Opsi Ingat Kasir -->
                        <div class="flex items-center justify-between pt-0.5">
                            <label class="flex items-center gap-2 cursor-pointer select-none">
                                <input
                                    type="checkbox"
                                    v-model="form.remember"
                                    class="w-4 h-4 rounded text-blue-600 border-slate-300 focus:ring-blue-500"
                                />
                                <span class="text-xs text-slate-600 font-medium">Tetap login di tablet kasir ini</span>
                            </label>
                        </div>

                        <!-- Tombol Submit Buka Kasir -->
                        <div class="pt-2">
                            <button
                                type="submit"
                                :disabled="form.processing"
                                class="w-full py-3.5 px-4 rounded-2xl bg-blue-600 hover:bg-blue-700 active:scale-[0.99] text-white font-bold text-sm uppercase tracking-wider shadow-lg shadow-blue-600/25 transition flex items-center justify-center gap-2 disabled:opacity-50 cursor-pointer"
                            >
                                <span v-if="form.processing" class="w-4 h-4 border-2 border-white/30 border-t-white rounded-full animate-spin"></span>
                                <span v-else>Masuk ke Meja Kasir</span>
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
                                </svg>
                            </button>
                        </div>

                    </form>
                </div>

            </div>

        </div>
    </div>
</template>
