-- XGEN LIFE Intl v3: Live Chat Support, CRM, ERP Finance (SAP-style)
-- Fresh installs: already included at the end of schema.sql.
-- Existing installs: run this file once (after upgrade_v2.sql).
SET NAMES utf8mb4;

-- ---------------------------------------------------------------
-- Live Chat Support
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS chat_threads (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  subject VARCHAR(150) NOT NULL DEFAULT 'Support chat',
  status ENUM('open','closed') NOT NULL DEFAULT 'open',
  assigned_admin_id INT UNSIGNED NULL,
  unread_admin INT UNSIGNED NOT NULL DEFAULT 0,
  unread_member INT UNSIGNED NOT NULL DEFAULT 0,
  last_message_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user (user_id, status),
  KEY idx_status (status, last_message_at),
  CONSTRAINT fk_chat_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS chat_messages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  thread_id INT UNSIGNED NOT NULL,
  sender_id INT UNSIGNED NULL,
  sender_role ENUM('member','admin','system') NOT NULL,
  body TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_thread (thread_id, id),
  CONSTRAINT fk_msg_thread FOREIGN KEY (thread_id) REFERENCES chat_threads(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------
-- CRM
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS crm_leads (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_user_id INT UNSIGNED NULL,          -- NULL = company lead, else the member who owns the prospect
  assigned_admin_id INT UNSIGNED NULL,
  name VARCHAR(120) NOT NULL,
  mobile VARCHAR(30) NULL,
  email VARCHAR(120) NULL,
  location VARCHAR(120) NULL,
  source VARCHAR(40) NOT NULL DEFAULT 'Referral',
  interest ENUM('product','business','both') NOT NULL DEFAULT 'both',
  status ENUM('new','contacted','presented','follow_up','converted','lost') NOT NULL DEFAULT 'new',
  next_follow_up DATE NULL,
  notes TEXT NULL,
  converted_user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_owner (owner_user_id, status),
  KEY idx_follow (next_follow_up),
  CONSTRAINT fk_lead_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_activities (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  lead_id INT UNSIGNED NULL,
  member_id INT UNSIGNED NULL,              -- activity about an existing member (member 360)
  author_id INT UNSIGNED NULL,
  type ENUM('note','call','sms','email','meeting','task') NOT NULL DEFAULT 'note',
  body TEXT NOT NULL,
  due_date DATE NULL,
  done TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_lead (lead_id),
  KEY idx_member (member_id),
  CONSTRAINT fk_act_lead FOREIGN KEY (lead_id) REFERENCES crm_leads(id) ON DELETE CASCADE,
  CONSTRAINT fk_act_member FOREIGN KEY (member_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------
-- ERP Finance (double-entry general ledger, SAP-style documents)
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS gl_accounts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(10) NOT NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  type ENUM('asset','liability','equity','revenue','expense') NOT NULL,
  description VARCHAR(255) NULL,
  is_system TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS journal_entries (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  doc_no VARCHAR(12) NOT NULL UNIQUE,
  doc_type CHAR(2) NOT NULL,                -- SA GL, DR sales, CM commission, ZP payout, KR expense, WA wallet adj, AB reversal
  posting_date DATE NOT NULL,
  memo VARCHAR(255) NOT NULL,
  ref_type VARCHAR(30) NULL,
  ref_id INT UNSIGNED NULL,
  total DECIMAL(14,2) NOT NULL DEFAULT 0,
  status ENUM('posted','reversed') NOT NULL DEFAULT 'posted',
  reversal_of INT UNSIGNED NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_date (posting_date),
  KEY idx_ref (ref_type, ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS journal_lines (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entry_id INT UNSIGNED NOT NULL,
  account_id INT UNSIGNED NOT NULL,
  debit DECIMAL(14,2) NOT NULL DEFAULT 0,
  credit DECIMAL(14,2) NOT NULL DEFAULT 0,
  memo VARCHAR(255) NULL,
  user_id INT UNSIGNED NULL,                -- member sub-ledger
  KEY idx_account (account_id),
  CONSTRAINT fk_line_entry FOREIGN KEY (entry_id) REFERENCES journal_entries(id) ON DELETE CASCADE,
  CONSTRAINT fk_line_account FOREIGN KEY (account_id) REFERENCES gl_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS expenses (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  expense_date DATE NOT NULL,
  vendor VARCHAR(120) NOT NULL,
  account_id INT UNSIGNED NOT NULL,
  paid_from_account_id INT UNSIGNED NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  reference VARCHAR(60) NULL,
  description VARCHAR(255) NULL,
  entry_id INT UNSIGNED NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_exp_account FOREIGN KEY (account_id) REFERENCES gl_accounts(id),
  CONSTRAINT fk_exp_paid FOREIGN KEY (paid_from_account_id) REFERENCES gl_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE wallet_transactions MODIFY category ENUM('earning','payout','payout_refund','purchase','purchase_refund','transfer_in','transfer_out','adjustment','code_purchase') NOT NULL;

INSERT IGNORE INTO gl_accounts (code, name, type, is_system, description) VALUES
 ('1000', 'Cash on Hand', 'asset', 1, 'Cash collected at the office'),
 ('1010', 'Cash in Bank', 'asset', 1, 'Company bank account (payouts are released from here)'),
 ('1020', 'PayMongo Clearing', 'asset', 1, 'Online payments not yet settled by PayMongo'),
 ('1030', 'GCash / Maya Wallet', 'asset', 1, 'Company e-wallet accounts'),
 ('1100', 'Accounts Receivable', 'asset', 0, NULL),
 ('1200', 'Inventory', 'asset', 0, NULL),
 ('1500', 'Property & Equipment', 'asset', 0, NULL),
 ('2000', 'Members\' Wallet Payable', 'liability', 1, 'Total of all member e-wallet balances'),
 ('2010', 'Payouts Payable', 'liability', 1, 'Requested withdrawals not yet released'),
 ('2100', 'Accounts Payable', 'liability', 0, NULL),
 ('2200', 'Taxes Payable', 'liability', 0, NULL),
 ('3000', 'Owner\'s Capital', 'equity', 0, NULL),
 ('3100', 'Retained Earnings', 'equity', 0, NULL),
 ('4000', 'Package / Activation Sales', 'revenue', 1, 'Sale of activation codes / packages'),
 ('4100', 'Product Sales', 'revenue', 1, 'Product orders at member price'),
 ('4200', 'Payout Processing Fees', 'revenue', 1, 'Fees deducted from withdrawals'),
 ('4900', 'Other Income', 'revenue', 1, NULL),
 ('5000', 'Commission - Direct Referral', 'expense', 1, NULL),
 ('5010', 'Commission - Indirect', 'expense', 1, NULL),
 ('5020', 'Commission - Pass Up', 'expense', 1, NULL),
 ('5030', 'Commission - Pairing / Matching', 'expense', 1, NULL),
 ('5040', 'Commission - Unilevel', 'expense', 1, NULL),
 ('5100', 'Wallet Adjustments', 'expense', 1, 'Manual wallet credits by admin'),
 ('5200', 'Payment Gateway Fees', 'expense', 0, NULL),
 ('5300', 'Cost of Goods Sold', 'expense', 0, NULL),
 ('6000', 'Salaries & Wages', 'expense', 0, NULL),
 ('6100', 'Rent', 'expense', 0, NULL),
 ('6200', 'Utilities', 'expense', 0, NULL),
 ('6300', 'Marketing & Events', 'expense', 0, NULL),
 ('6400', 'Shipping & Delivery', 'expense', 0, NULL),
 ('6500', 'Office Supplies', 'expense', 0, NULL),
 ('6900', 'Miscellaneous Expense', 'expense', 0, NULL);

INSERT IGNORE INTO settings (skey, svalue) VALUES
 ('chat_enabled', '1'),
 ('chat_welcome', 'Hi! 👋 How can we help you today? Our support team will reply shortly.'),
 ('chat_hours', 'Mon–Sat, 8:00 AM – 6:00 PM'),
 ('chat_quick_replies', "Hello! Thank you for contacting XGEN LIFE support.\nWe are checking this for you, please wait a moment.\nYour payout has been processed. Please check your account.\nIs there anything else we can help you with?"),
 ('crm_sources', 'Facebook,Referral,Walk-in,Event,Website,TikTok,Other'),
 ('erp_enabled', '1'),
 ('erp_lock_date', ''),
 ('acct_cash', '1000'),
 ('acct_bank', '1010'),
 ('acct_paymongo', '1020'),
 ('acct_ewallet', '1030'),
 ('acct_wallet_payable', '2000'),
 ('acct_payouts_payable', '2010'),
 ('acct_package_sales', '4000'),
 ('acct_product_sales', '4100'),
 ('acct_payout_fees', '4200'),
 ('acct_other_income', '4900'),
 ('acct_comm_direct', '5000'),
 ('acct_comm_indirect', '5010'),
 ('acct_comm_passup', '5020'),
 ('acct_comm_pairing', '5030'),
 ('acct_comm_unilevel', '5040'),
 ('acct_wallet_adjust', '5100'),
 ('acct_payout_source', '1010');
