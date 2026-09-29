/**
 * A side panel for the pricing editors: the free runtime's Sheet parts
 * (free main after M07 T5b), or its Dialog parts on an older free plugin —
 * never a copy of either.
 */
import { Suspense } from 'react';
import * as ui from '@rtbp/ui';

/**
 * @param {Object}      props             Props.
 * @param {string}      props.title       Title.
 * @param {string}      props.description One line under the title.
 * @param {Function}    props.onClose     Close.
 * @param {JSX.Element} props.children    Body.
 * @param {JSX.Element} props.footer      Buttons.
 * @return {JSX.Element} Panel.
 */
export default function Drawer( {
	title,
	description,
	onClose,
	children,
	footer,
} ) {
	const sheet = Boolean( ui.Sheet && ui.SheetContent );
	const Root = sheet ? ui.Sheet : ui.Dialog;
	const Content = sheet ? ui.SheetContent : ui.DialogContent;
	const Header = sheet ? ui.SheetHeader : ui.DialogHeader;
	const Title = sheet ? ui.SheetTitle : ui.DialogTitle;
	const Description = sheet ? ui.SheetDescription : ui.DialogDescription;

	return (
		<Suspense fallback={ null }>
			<Root open onOpenChange={ ( open ) => ! open && onClose() }>
				<Content
					className={
						sheet
							? 'flex w-full flex-col gap-0 p-0 sm:max-w-lg'
							: 'flex max-h-[90vh] max-w-lg flex-col gap-0 p-0'
					}
				>
					<Header className="border-b border-border px-5 py-4 pr-14 text-left">
						<Title>{ title }</Title>
						{ description ? (
							<Description>{ description }</Description>
						) : null }
					</Header>
					<div className="min-h-0 flex-1 space-y-5 overflow-y-auto px-5 py-5">
						{ children }
					</div>
					{ footer ? (
						<div className="flex flex-wrap items-center justify-between gap-2 border-t border-border px-5 py-3">
							{ footer }
						</div>
					) : null }
				</Content>
			</Root>
		</Suspense>
	);
}
