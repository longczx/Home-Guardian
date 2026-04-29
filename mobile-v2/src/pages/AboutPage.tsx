import React from 'react';
import { useNavigate } from 'react-router-dom';
import { ArrowLeft, Shield, Cpu, Wifi, BarChart3, Zap, Github } from 'lucide-react';

const TECH_STACK = [
  { name: 'React 19',         desc: 'UI 框架',              icon: '⚛️' },
  { name: 'TypeScript',       desc: '类型安全',              icon: '🔷' },
  { name: 'Vite 5',           desc: '构建工具',              icon: '⚡' },
  { name: 'TailwindCSS 3',    desc: 'CSS 框架',             icon: '🎨' },
  { name: 'Zustand',          desc: '状态管理',              icon: '🐻' },
  { name: 'React Router 7',   desc: '路由',                 icon: '🔀' },
  { name: 'ECharts 5',        desc: '数据可视化',            icon: '📊' },
  { name: 'WebSocket',        desc: '实时通信',              icon: '🔌' },
  { name: 'Webman / PHP 8.2', desc: '后端框架',              icon: '🐘' },
  { name: 'EMQX MQTT',        desc: 'IoT 消息代理',          icon: '📡' },
  { name: 'MySQL 8',          desc: '数据库',                icon: '🗄️' },
  { name: 'JWT 双令牌',        desc: 'access + refresh token', icon: '🔑' },
];

const FEATURES = [
  { icon: Cpu,     text: '多设备实时监控' },
  { icon: Wifi,    text: 'MQTT/WebSocket 推送' },
  { icon: BarChart3, text: '遥测历史图表' },
  { icon: Shield,  text: '告警规则引擎' },
  { icon: Zap,     text: '自动化联动' },
];

export default function AboutPage() {
  const navigate = useNavigate();
  return (
    <div className="page-container bg-slate-50 dark:bg-slate-950 overflow-y-auto">
      {/* Header */}
      <div className="flex items-center gap-3 px-4 pt-4 pb-2">
        <button onClick={() => navigate(-1)} className="p-2 -ml-2">
          <ArrowLeft className="w-6 h-6 text-slate-600 dark:text-slate-400" />
        </button>
        <h1 className="text-lg font-bold text-slate-900 dark:text-slate-100">关于</h1>
      </div>

      <div className="px-4 pb-8 space-y-4">
        {/* Brand hero */}
        <div className="hero-card bg-gradient-to-br from-indigo-500 to-violet-600 p-6 text-white text-center">
          <div className="w-16 h-16 bg-white/15 rounded-3xl flex items-center justify-center mx-auto mb-4">
            <Shield className="w-8 h-8 text-white" />
          </div>
          <h2 className="text-2xl font-black">Home Guardian</h2>
          <p className="text-white/60 text-sm mt-1">智能家居守护平台</p>
          <div className="flex items-center justify-center gap-3 mt-4">
            <span className="bg-white/15 rounded-full text-xs px-3 py-1 font-medium">v 2.0.0</span>
            <span className="bg-white/15 rounded-full text-xs px-3 py-1 font-medium">Mobile UI</span>
          </div>
        </div>

        {/* Features */}
        <div className="card p-4">
          <h3 className="text-xs font-bold text-slate-400 dark:text-slate-500 uppercase tracking-wider mb-3">核心功能</h3>
          <div className="grid grid-cols-1 gap-2">
            {FEATURES.map(({ icon: Icon, text }) => (
              <div key={text} className="flex items-center gap-3">
                <div className="w-8 h-8 bg-indigo-50 dark:bg-indigo-900/20 rounded-xl flex items-center justify-center flex-shrink-0">
                  <Icon className="w-4 h-4 text-indigo-500" />
                </div>
                <p className="text-sm text-slate-700 dark:text-slate-300">{text}</p>
              </div>
            ))}
          </div>
        </div>

        {/* Tech stack */}
        <div className="card p-4">
          <h3 className="text-xs font-bold text-slate-400 dark:text-slate-500 uppercase tracking-wider mb-3">技术栈</h3>
          <div className="grid grid-cols-2 gap-2">
            {TECH_STACK.map(({ name, desc, icon }) => (
              <div key={name} className="flex items-center gap-2.5 py-2">
                <span className="text-xl leading-none">{icon}</span>
                <div>
                  <p className="text-xs font-semibold text-slate-700 dark:text-slate-300">{name}</p>
                  <p className="text-[10px] text-slate-400 dark:text-slate-500">{desc}</p>
                </div>
              </div>
            ))}
          </div>
        </div>

        {/* Version info */}
        <div className="card p-4 space-y-2">
          <h3 className="text-xs font-bold text-slate-400 dark:text-slate-500 uppercase tracking-wider mb-3">版本信息</h3>
          {[
            { label: '应用版本', value: '2.0.0' },
            { label: '构建工具', value: 'Vite 5.4' },
            { label: '运行时', value: 'React 19' },
            { label: '目标平台', value: 'iOS / Android / Web' },
          ].map(({ label, value }) => (
            <div key={label} className="flex justify-between">
              <p className="text-xs text-slate-400 dark:text-slate-500">{label}</p>
              <p className="text-xs font-medium text-slate-700 dark:text-slate-300">{value}</p>
            </div>
          ))}
        </div>

        <p className="text-center text-xs text-slate-400 dark:text-slate-600 py-2">
          © 2024 Home Guardian · MIT License
        </p>
      </div>
    </div>
  );
}
