import { useState } from 'react';
import { AppProvider, type TabKey } from './state/AppContext';
import { TopBar } from './components/TopBar';
import { PriceStrip } from './components/PriceStrip';
import { TabBar, BottomNav } from './components/Navigation';
import { AdBanner } from './components/AdBanner';
import { NewGoldTab } from './screens/tabs/NewGoldTab';
import { SecondHandTab } from './screens/tabs/SecondHandTab';
import { CoinTab } from './screens/tabs/CoinTab';
import { ReverseTab } from './screens/tabs/ReverseTab';
import { SettingsSheet } from './components/SettingsSheet';
import { ConverterSheet } from './components/ConverterSheet';
import { InvoicePreview } from './screens/InvoicePreview';
import { LockIcon } from './components/icons';

function Shell() {
  const [tab, setTab] = useState<TabKey>('new');
  const [settingsOpen, setSettingsOpen] = useState(false);
  const [converterOpen, setConverterOpen] = useState(false);
  const [invoiceOpen, setInvoiceOpen] = useState(false);

  return (
    <div className="app">
      <div className="app-main">
        <TopBar onSettings={() => setSettingsOpen(true)} />
        <div className="content">
          <PriceStrip />
          <TabBar tab={tab} onChange={setTab} />

          {tab === 'new' && <NewGoldTab onShareInvoice={() => setInvoiceOpen(true)} />}
          {tab === 'second' && <SecondHandTab />}
          {tab === 'coin' && <CoinTab />}
          {tab === 'reverse' && <ReverseTab />}

          <p className="footer-note">
            <LockIcon size={13} />
            محاسبه تقریبی، ملاک نرخ طلافروشی است.
          </p>
        </div>
      </div>

      <BottomNav tab={tab} onChange={setTab} />
      <AdBanner />

      {settingsOpen && (
        <SettingsSheet
          onClose={() => setSettingsOpen(false)}
          onOpenConverter={() => {
            setSettingsOpen(false);
            setConverterOpen(true);
          }}
        />
      )}
      {converterOpen && <ConverterSheet onClose={() => setConverterOpen(false)} />}
      {invoiceOpen && <InvoicePreview onClose={() => setInvoiceOpen(false)} />}
    </div>
  );
}

export default function App() {
  return (
    <AppProvider>
      <Shell />
    </AppProvider>
  );
}
