import './globals.css';

export const metadata = {
  title: 'Nexora Standby',
  description: 'Alternate Next.js and Node.js platform foundation'
};

export default function RootLayout({ children }) {
  return (
    <html lang="en">
      <body>{children}</body>
    </html>
  );
}
